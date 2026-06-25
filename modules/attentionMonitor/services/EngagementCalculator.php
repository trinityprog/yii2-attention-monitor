<?php

namespace app\modules\attentionMonitor\services;

use app\modules\attentionMonitor\models\HandEvent;
use app\modules\attentionMonitor\models\Session;
use app\modules\attentionMonitor\models\StateInterval;

/**
 * Turns a session's raw state intervals into the report numbers the teacher
 * sees: per-state time/percentages, episode counts, longest streaks, the
 * 0-100 engagement score, the colored timeline and the per-minute chart
 * series. Pure computation, no persistence or framework dependency beyond
 * the two models it reads.
 */
class EngagementCalculator
{
    /**
     * @param Session $session
     * @param StateInterval[] $intervals ordered by started_at ascending
     * @param float $distractedWeight weight of "distracted" seconds in the score, 0..1
     * @param HandEvent[] $handEvents ordered by started_at ascending (bonus "Активность" feature)
     */
    public static function compute(Session $session, array $intervals, float $distractedWeight = 0.5, array $handEvents = []): array
    {
        $sessionStart = (int) $session->started_at;
        $sessionEnd = (int) ($session->ended_at ?? self::lastEndedAt($intervals) ?? $sessionStart);
        $totalDuration = max(0, $sessionEnd - $sessionStart);

        $seconds = [
            StateInterval::STATE_ENGAGED => 0,
            StateInterval::STATE_DISTRACTED => 0,
            StateInterval::STATE_ABSENT => 0,
        ];
        $episodeCounts = [
            StateInterval::STATE_DISTRACTED => 0,
            StateInterval::STATE_ABSENT => 0,
        ];
        $longestByState = [
            StateInterval::STATE_ENGAGED => null,
            StateInterval::STATE_ABSENT => null,
        ];

        foreach ($intervals as $interval) {
            $duration = $interval->getDuration();
            $seconds[$interval->state] += $duration;

            if (isset($episodeCounts[$interval->state])) {
                $episodeCounts[$interval->state]++;
            }

            if (array_key_exists($interval->state, $longestByState)) {
                $current = $longestByState[$interval->state];
                if ($current === null || $duration > $current['durationSeconds']) {
                    $longestByState[$interval->state] = [
                        'durationSeconds' => $duration,
                        'startOffsetSeconds' => $interval->started_at - $sessionStart,
                        'startedAt' => $interval->started_at,
                    ];
                }
            }
        }

        // Seconds not covered by any recorded interval (e.g. a batch lost to
        // a dropped connection) are excluded from every percentage below
        // rather than guessed at - we only score what was actually observed.
        $coveredSeconds = array_sum($seconds);

        $byState = [];
        foreach ($seconds as $state => $value) {
            $byState[$state] = [
                'seconds' => $value,
                'percent' => $coveredSeconds > 0 ? round($value / $coveredSeconds * 100, 1) : 0.0,
            ];
        }

        $score = $coveredSeconds > 0
            ? (int) round(
                ($seconds[StateInterval::STATE_ENGAGED] + $distractedWeight * $seconds[StateInterval::STATE_DISTRACTED])
                / $coveredSeconds * 100
            )
            : 0;

        return [
            'sessionId' => $session->id,
            'studentId' => $session->student_id,
            'startedAt' => $sessionStart,
            'endedAt' => $session->ended_at !== null ? $sessionEnd : null,
            'durationSeconds' => $totalDuration,
            'byState' => $byState,
            'distractionCount' => $episodeCounts[StateInterval::STATE_DISTRACTED],
            'absenceCount' => $episodeCounts[StateInterval::STATE_ABSENT],
            'longestEngagement' => $longestByState[StateInterval::STATE_ENGAGED],
            'longestAbsence' => $longestByState[StateInterval::STATE_ABSENT],
            'engagementScore' => $score,
            'timeline' => self::buildTimeline($intervals, $sessionStart),
            'perMinute' => self::buildEngagementSeries($intervals, $sessionStart, $sessionEnd, $distractedWeight),
            'activity' => self::buildActivity($handEvents),
        ];
    }

    /**
     * @param HandEvent[] $handEvents
     */
    private static function buildActivity(array $handEvents): array
    {
        $totalSeconds = 0;
        foreach ($handEvents as $event) {
            $totalSeconds += $event->getDuration();
        }

        return [
            'handRaiseCount' => count($handEvents),
            'handRaiseSeconds' => $totalSeconds,
        ];
    }

    private static function lastEndedAt(array $intervals): ?int
    {
        $max = null;
        foreach ($intervals as $interval) {
            if ($max === null || $interval->ended_at > $max) {
                $max = $interval->ended_at;
            }
        }

        return $max;
    }

    private static function buildTimeline(array $intervals, int $sessionStart): array
    {
        $timeline = [];
        foreach ($intervals as $interval) {
            $timeline[] = [
                'state' => $interval->state,
                'startOffsetSeconds' => $interval->started_at - $sessionStart,
                'durationSeconds' => $interval->getDuration(),
            ];
        }

        return $timeline;
    }

    /**
     * Bucket width is chosen from the session's total length, not hardcoded
     * to a minute - a 1-minute lesson plotted in 1-minute buckets is a
     * single bar (useless), and a 2-hour lesson in 1-minute buckets is 120
     * bars (unreadable). Pick the smallest "round" width from this ladder
     * that still keeps the chart under MAX_BUCKETS bars, so short sessions
     * get fine (down to 5s) granularity and long ones stay readable.
     */
    private const BUCKET_LADDER_SECONDS = [5, 10, 15, 30, 60, 120, 300, 600, 900, 1800];
    private const MAX_BUCKETS = 30;

    private static function pickBucketSeconds(int $totalDuration): int
    {
        foreach (self::BUCKET_LADDER_SECONDS as $bucketSeconds) {
            if ((int) ceil($totalDuration / $bucketSeconds) <= self::MAX_BUCKETS) {
                return $bucketSeconds;
            }
        }

        return self::BUCKET_LADDER_SECONDS[count(self::BUCKET_LADDER_SECONDS) - 1];
    }

    private static function buildEngagementSeries(array $intervals, int $sessionStart, int $sessionEnd, float $distractedWeight): array
    {
        $totalDuration = $sessionEnd - $sessionStart;
        if ($totalDuration <= 0) {
            return [];
        }

        $bucketSeconds = self::pickBucketSeconds($totalDuration);
        $bucketCount = (int) ceil($totalDuration / $bucketSeconds);
        $series = [];

        for ($i = 0; $i < $bucketCount; $i++) {
            $bucketStart = $sessionStart + $i * $bucketSeconds;
            $bucketEnd = min($bucketStart + $bucketSeconds, $sessionEnd);

            $weighted = 0;
            $covered = 0;
            foreach ($intervals as $interval) {
                $overlap = min($interval->ended_at, $bucketEnd) - max($interval->started_at, $bucketStart);
                if ($overlap <= 0) {
                    continue;
                }

                $covered += $overlap;
                if ($interval->state === StateInterval::STATE_ENGAGED) {
                    $weighted += $overlap;
                } elseif ($interval->state === StateInterval::STATE_DISTRACTED) {
                    $weighted += $overlap * $distractedWeight;
                }
            }

            $series[] = [
                'offsetSeconds' => $i * $bucketSeconds,
                'label' => Format::offset($i * $bucketSeconds),
                'engagementPercent' => $covered > 0 ? round($weighted / $covered * 100, 1) : 0.0,
            ];
        }

        return $series;
    }
}
