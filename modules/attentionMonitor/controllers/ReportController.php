<?php

namespace app\modules\attentionMonitor\controllers;

use app\modules\attentionMonitor\models\Session;
use app\modules\attentionMonitor\services\EngagementCalculator;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class ReportController extends Controller
{
    /**
     * @throws NotFoundHttpException if the session does not exist - handled
     * by Yii's standard error page (404), never an uncaught fatal.
     */
    public function actionView($id)
    {
        $session = Session::findOne($id);
        if ($session === null) {
            throw new NotFoundHttpException("Сессия #$id не найдена.");
        }

        // Finished sessions use the snapshot cached at finish time; a report
        // opened while the lesson is still running (allowed by the spec)
        // has no snapshot yet and is computed live from intervals so far.
        // A cache from before the "activity" field existed, or before
        // perMinute switched from fixed 1-minute buckets to a duration-based
        // bucket width (no "label" key), is treated as stale and recomputed.
        $cached = $session->getCachedStats();
        $hasCurrentPerMinuteFormat = $cached === null
            || $cached['perMinute'] === []
            || isset($cached['perMinute'][0]['label']);
        $stats = $session->isFinished() && $cached !== null && isset($cached['activity']) && $hasCurrentPerMinuteFormat
            ? $cached
            : EngagementCalculator::compute(
                $session,
                $session->getIntervals()->all(),
                $this->module->distractedWeight,
                $session->getHandEvents()->all()
            );

        return $this->render('view', [
            'session' => $session,
            'stats' => $stats,
        ]);
    }
}
