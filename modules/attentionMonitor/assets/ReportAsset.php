<?php

namespace app\modules\attentionMonitor\assets;

use yii\web\AssetBundle;

class ReportAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/../web';

    public $css = [
        'css/style.css',
    ];

    public $js = [
        'js/report.js',
    ];

    public $depends = [
        ChartJsAsset::class,
    ];
}
