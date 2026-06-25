<?php

namespace app\modules\attentionMonitor\controllers;

use app\modules\attentionMonitor\models\HandEvent;
use app\modules\attentionMonitor\models\Session;
use app\modules\attentionMonitor\models\StateInterval;
use app\modules\attentionMonitor\services\EngagementCalculator;
use Yii;
use yii\filters\VerbFilter;
use yii\helpers\Url;
use yii\web\Controller;
use yii\web\Response;

/**
 * JSON API consumed by the capture page's JS. Never throws an uncaught
 * exception for bad input or a missing session - every failure path below
 * is a deliberate, structured JSON response instead.
 */
class SessionController extends Controller
{
    // Anonymous JSON API hit via fetch() from the capture page, not a
    // browser-submitted form, so there is no session-backed CSRF token to
    // check against.
    public $enableCsrfValidation = false;

    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'start' => ['post'],
                    'ingest' => ['post'],
                    'finish' => ['post'],
                ],
            ],
        ]);
    }

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        return parent::beforeAction($action);
    }

    public function actionStart()
    {
        $studentId = trim((string) Yii::$app->request->post('studentId', ''));
        if ($studentId === '') {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'student_id_required'];
        }

        $session = new Session([
            'student_id' => $studentId,
            'started_at' => time(),
            'status' => Session::STATUS_ACTIVE,
        ]);

        if (!$session->save()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'validation_failed', 'details' => $session->getErrors()];
        }

        return [
            'sessionId' => $session->id,
            'studentId' => $session->student_id,
            'startedAt' => $session->started_at,
        ];
    }

    public function actionIngest($id)
    {
        $session = Session::findOne($id);
        if ($session === null) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'session_not_found'];
        }

        if ($session->isFinished()) {
            Yii::$app->response->statusCode = 409;
            return ['error' => 'session_already_finished'];
        }

        return $this->ingestBatch($session, Yii::$app->request->getRawBody());
    }

    public function actionFinish($id)
    {
        $session = Session::findOne($id);
        if ($session === null) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'session_not_found'];
        }

        // A trailing batch (closing whatever interval/hand-event was still
        // open on the client) may be sent together with finish - same
        // tolerant parsing as ingest.
        $result = ['accepted' => 0, 'rejected' => 0, 'errors' => []];
        if (!$session->isFinished()) {
            $result = $this->ingestBatch($session, Yii::$app->request->getRawBody());
        }

        if (!$session->isFinished()) {
            $lastEndedAt = (int) StateInterval::find()
                ->where(['session_id' => $session->id])
                ->max('ended_at');

            $session->ended_at = max(time(), $lastEndedAt, $session->started_at);
            $session->status = Session::STATUS_FINISHED;

            $intervals = $session->getIntervals()->all();
            $handEvents = $session->getHandEvents()->all();
            $module = Yii::$app->getModule('attention-monitor');
            $stats = EngagementCalculator::compute($session, $intervals, $module->distractedWeight, $handEvents);
            $session->stats = json_encode($stats);

            $session->save(false);
        }

        return [
            'sessionId' => $session->id,
            'accepted' => $result['accepted'],
            'rejected' => $result['rejected'],
            'errors' => $result['errors'],
            'reportUrl' => Url::to(['/attention-monitor/report/view', 'id' => $session->id]),
        ];
    }

    /**
     * Validates and persists one batch of {@see StateInterval} and/or
     * {@see HandEvent} rows from a raw JSON body. Used by both ingest and
     * finish, since finish accepts a trailing batch too.
     */
    private function ingestBatch(Session $session, string $rawBody): array
    {
        if ($rawBody === '') {
            return ['accepted' => 0, 'rejected' => 0, 'errors' => []];
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['accepted' => 0, 'rejected' => 1, 'errors' => ['invalid_json']];
        }

        if (!is_array($payload)) {
            return ['accepted' => 0, 'rejected' => 1, 'errors' => ['payload_not_an_object']];
        }

        [$intervalRows, $intervalsAccepted, $intervalsRejected, $intervalErrors] =
            $this->parseIntervals($session, $payload);
        [$handRows, $handAccepted, $handRejected, $handErrors] =
            $this->parseHandEvents($session, $payload);

        if ($intervalRows !== []) {
            // Single batchInsert instead of one INSERT per interval - the
            // part that matters once ~30 students are each flushing a batch
            // every few seconds.
            Yii::$app->db->createCommand()->batchInsert(
                StateInterval::tableName(),
                ['session_id', 'state', 'started_at', 'ended_at'],
                $intervalRows
            )->execute();
        }

        if ($handRows !== []) {
            Yii::$app->db->createCommand()->batchInsert(
                HandEvent::tableName(),
                ['session_id', 'started_at', 'ended_at'],
                $handRows
            )->execute();
        }

        return [
            'accepted' => $intervalsAccepted + $handAccepted,
            'rejected' => $intervalsRejected + $handRejected,
            'errors' => array_merge($intervalErrors, $handErrors),
        ];
    }

    /**
     * Validates the "intervals" key of an already-decoded payload:
     * {"intervals":[{"state":"engaged","startedAt":123,"endedAt":124}, ...]}.
     * Every malformed entry is skipped and counted rather than failing the
     * whole batch - the request can never come back as a 500 because of bad
     * client data. A missing/absent key is not an error - hand-only batches
     * are valid.
     *
     * @return array{0: array, 1: int, 2: int, 3: string[]} [rows, accepted, rejected, errors]
     */
    private function parseIntervals(Session $session, array $payload): array
    {
        $rows = [];
        $accepted = 0;
        $rejected = 0;
        $errors = [];

        if (!isset($payload['intervals'])) {
            return [$rows, $accepted, $rejected, $errors];
        }

        $intervals = $payload['intervals'];
        if (!is_array($intervals)) {
            return [$rows, 0, 1, ['intervals_not_an_array']];
        }

        foreach ($intervals as $index => $entry) {
            if (!is_array($entry)
                || !isset($entry['state'], $entry['startedAt'], $entry['endedAt'])
                || !in_array($entry['state'], StateInterval::STATES, true)
                || !is_numeric($entry['startedAt'])
                || !is_numeric($entry['endedAt'])
                || (int) $entry['endedAt'] < (int) $entry['startedAt']
            ) {
                $rejected++;
                $errors[] = "interval[$index]_malformed";
                continue;
            }

            $rows[] = [
                $session->id,
                $entry['state'],
                (int) $entry['startedAt'],
                (int) $entry['endedAt'],
            ];
            $accepted++;
        }

        return [$rows, $accepted, $rejected, $errors];
    }

    /**
     * Validates the "handEvents" key of an already-decoded payload (bonus
     * "Активность" feature): {"handEvents":[{"startedAt":1,"endedAt":2}]}.
     * Same tolerant, per-item validation as {@see parseIntervals}.
     *
     * @return array{0: array, 1: int, 2: int, 3: string[]} [rows, accepted, rejected, errors]
     */
    private function parseHandEvents(Session $session, array $payload): array
    {
        $rows = [];
        $accepted = 0;
        $rejected = 0;
        $errors = [];

        if (!isset($payload['handEvents'])) {
            return [$rows, $accepted, $rejected, $errors];
        }

        $events = $payload['handEvents'];
        if (!is_array($events)) {
            return [$rows, 0, 1, ['hand_events_not_an_array']];
        }

        foreach ($events as $index => $entry) {
            if (!is_array($entry)
                || !isset($entry['startedAt'], $entry['endedAt'])
                || !is_numeric($entry['startedAt'])
                || !is_numeric($entry['endedAt'])
                || (int) $entry['endedAt'] < (int) $entry['startedAt']
            ) {
                $rejected++;
                $errors[] = "handEvent[$index]_malformed";
                continue;
            }

            $rows[] = [
                $session->id,
                (int) $entry['startedAt'],
                (int) $entry['endedAt'],
            ];
            $accepted++;
        }

        return [$rows, $accepted, $rejected, $errors];
    }
}
