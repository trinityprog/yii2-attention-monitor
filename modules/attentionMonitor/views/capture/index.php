<?php

/** @var yii\web\View $this */

use app\modules\attentionMonitor\assets\CaptureAsset;
use yii\helpers\Json;
use yii\helpers\Url;

CaptureAsset::register($this);

$this->title = 'Attention Monitor — урок';

$config = [
    'startUrl' => Url::to(['/attention-monitor/session/start']),
    'ingestUrl' => Url::to(['/attention-monitor/session/ingest']),
    'finishUrl' => Url::to(['/attention-monitor/session/finish']),
];

$this->registerJs('window.__AM_CAPTURE_CONFIG__ = ' . Json::encode($config) . ';', \yii\web\View::POS_HEAD);
?>
<div class="am-app">
    <div class="am-page-header">
        <h1>Урок: мониторинг внимания</h1>
        <p>Камера и видео остаются в браузере — на сервер уходят только метки состояний.</p>
    </div>

    <div class="am-card">
        <div id="am-setup" class="am-setup">
            <input type="text" id="am-student-id" placeholder="ID ученика" value="demo-student">
            <button type="button" id="am-start-btn" class="am-btn am-btn-primary">Начать урок</button>
        </div>

        <div id="am-session" class="am-session am-hidden">
            <div class="am-video-wrap">
                <video id="am-video" autoplay playsinline muted></video>
                <canvas id="am-overlay"></canvas>
                <div id="am-state-badge" class="am-state-badge">…</div>
                <div id="am-hand-badge" class="am-hand-badge am-hidden">✋ рука поднята</div>
            </div>
            <div class="am-session-actions">
                <button type="button" id="am-finish-btn" class="am-btn am-btn-danger">Завершить урок</button>
            </div>
            <div id="am-status" class="am-status"></div>
        </div>

        <div id="am-error" class="am-error am-hidden"></div>
    </div>
</div>
