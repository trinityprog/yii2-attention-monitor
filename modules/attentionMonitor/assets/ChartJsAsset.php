<?php

namespace app\modules\attentionMonitor\assets;

use yii\web\AssetBundle;

/**
 * Loaded straight from a CDN (no sourcePath - Yii outputs external URLs
 * as-is instead of trying to publish them locally).
 */
class ChartJsAsset extends AssetBundle
{
    public $js = [
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
    ];
}
