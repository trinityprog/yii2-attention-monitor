<?php

namespace app\modules\attentionMonitor\services;

/**
 * Small display-formatting helpers shared by the report view.
 */
class Format
{
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        if ($minutes === 0) {
            return "{$rest} сек";
        }

        return "{$minutes} мин {$rest} сек";
    }

    public static function offset(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $rest);
    }

    public static function clock(int $timestamp): string
    {
        return date('H:i:s', $timestamp);
    }
}
