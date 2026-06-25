<?php

namespace app\modules\attentionMonitor\assets;

use yii\web\AssetBundle;

class CaptureAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/../web';

    public $css = [
        'css/style.css',
    ];

    public $js = [
        ['js/capture.js', 'type' => 'module'],
    ];
}
