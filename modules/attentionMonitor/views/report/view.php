<?php

/**
 * @var yii\web\View $this
 * @var app\modules\attentionMonitor\models\Session $session
 * @var array $stats
 */

use app\modules\attentionMonitor\assets\ReportAsset;
use app\modules\attentionMonitor\models\StateInterval;
use app\modules\attentionMonitor\services\Format;
use yii\helpers\Html;
use yii\helpers\Json;

ReportAsset::register($this);

$this->title = 'Отчёт по уроку #' . $session->id;

$this->registerJs('window.__AM_REPORT_DATA__ = ' . Json::encode(['perMinute' => $stats['perMinute']]) . ';', \yii\web\View::POS_HEAD);

$stateLabels = [
    StateInterval::STATE_ENGAGED => 'Вовлечён',
    StateInterval::STATE_DISTRACTED => 'Отвлечён',
    StateInterval::STATE_ABSENT => 'Отсутствует',
];

$stateAccent = [
    StateInterval::STATE_ENGAGED => 'engaged',
    StateInterval::STATE_DISTRACTED => 'distracted',
    StateInterval::STATE_ABSENT => 'absent',
];

$totalDuration = max(1, $stats['durationSeconds']); // avoid div-by-zero in the timeline below

$score = (int) $stats['engagementScore'];
$scoreClass = $score >= 70 ? 'am-score-good' : ($score >= 40 ? 'am-score-mid' : 'am-score-low');
?>
<div class="am-app am-app-wide">
    <div class="am-page-header">
        <h1>Отчёт по уроку #<?= (int) $stats['sessionId'] ?></h1>
        <p>Ученик: <strong><?= Html::encode($stats['studentId']) ?></strong></p>
    </div>

    <?php if (!$session->isFinished()): ?>
        <div class="am-banner-info">Урок ещё продолжается — показаны данные по текущий момент.</div>
    <?php endif; ?>

    <div class="am-report-row">
        <div class="am-card">
            <h2>Сессия</h2>
            <div class="am-meta-row">
                <div class="am-meta-item">
                    <div class="am-meta-label">Начало</div>
                    <div class="am-meta-value"><?= Format::clock($stats['startedAt']) ?></div>
                </div>
                <div class="am-meta-item">
                    <div class="am-meta-label">Окончание</div>
                    <div class="am-meta-value"><?= $stats['endedAt'] !== null ? Format::clock($stats['endedAt']) : '—' ?></div>
                </div>
                <div class="am-meta-item">
                    <div class="am-meta-label">Длительность</div>
                    <div class="am-meta-value"><?= Format::duration($stats['durationSeconds']) ?></div>
                </div>
            </div>
        </div>

        <div class="am-card">
            <h2>Общий показатель вовлечённости</h2>
            <div class="am-score-wrap">
                <div class="am-score <?= $scoreClass ?>"><?= $score ?><span class="am-score-max"> / 100</span></div>
                <div class="am-score-bar-track">
                    <div class="am-score-bar-fill <?= $scoreClass ?>" style="width: <?= $score ?>%; background: currentColor;"></div>
                </div>
            </div>
        </div>

        <div class="am-card">
            <h2>Активность</h2>
            <div class="am-stats-grid">
                <div class="am-stat-card am-accent-info">
                    <div class="am-stat-label">Поднимал руку (раз)</div>
                    <div class="am-stat-value"><?= (int) $stats['activity']['handRaiseCount'] ?></div>
                </div>
                <div class="am-stat-card am-accent-info">
                    <div class="am-stat-label">Общее время с поднятой рукой</div>
                    <div class="am-stat-value"><?= Format::duration($stats['activity']['handRaiseSeconds']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="am-card">
        <h2>По состояниям</h2>
        <div class="am-stats-grid">
            <?php foreach ($stateLabels as $state => $label): ?>
                <div class="am-stat-card am-accent-<?= $stateAccent[$state] ?>">
                    <div class="am-stat-label"><?= $label ?></div>
                    <div class="am-stat-value"><?= Format::duration($stats['byState'][$state]['seconds']) ?></div>
                    <div class="am-stat-label"><?= $stats['byState'][$state]['percent'] ?>%</div>
                </div>
            <?php endforeach; ?>
            <div class="am-stat-card">
                <div class="am-stat-label">Отвлечений (раз)</div>
                <div class="am-stat-value"><?= (int) $stats['distractionCount'] ?></div>
            </div>
            <div class="am-stat-card">
                <div class="am-stat-label">Выходов из кадра (раз)</div>
                <div class="am-stat-value"><?= (int) $stats['absenceCount'] ?></div>
            </div>
            <div class="am-stat-card">
                <div class="am-stat-label">Самый длинный эпизод отсутствия</div>
                <div class="am-stat-value">
                    <?= $stats['longestAbsence'] !== null ? Format::duration($stats['longestAbsence']['durationSeconds']) : '—' ?>
                </div>
                <?php if ($stats['longestAbsence'] !== null): ?>
                    <div class="am-stat-label">на <?= Format::offset($stats['longestAbsence']['startOffsetSeconds']) ?> урока</div>
                <?php endif; ?>
            </div>
            <div class="am-stat-card">
                <div class="am-stat-label">Самый длинный эпизод вовлечённости</div>
                <div class="am-stat-value">
                    <?= $stats['longestEngagement'] !== null ? Format::duration($stats['longestEngagement']['durationSeconds']) : '—' ?>
                </div>
                <?php if ($stats['longestEngagement'] !== null): ?>
                    <div class="am-stat-label">на <?= Format::offset($stats['longestEngagement']['startOffsetSeconds']) ?> урока</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="am-card">
        <h2>Таймлайн урока</h2>
        <?php if ($stats['timeline'] === []): ?>
            <p><em>Пока нет данных наблюдений.</em></p>
        <?php else: ?>
            <div class="am-timeline">
                <?php foreach ($stats['timeline'] as $segment): ?>
                    <?php $widthPercent = $segment['durationSeconds'] / $totalDuration * 100; ?>
                    <div
                        class="am-timeline-segment am-state-<?= $segment['state'] ?>"
                        style="width: <?= $widthPercent ?>%"
                        title="<?= $stateLabels[$segment['state']] ?>: <?= Format::offset($segment['startOffsetSeconds']) ?>, <?= Format::duration($segment['durationSeconds']) ?>"
                    ></div>
                <?php endforeach; ?>
            </div>
            <div class="am-timeline-scale">
                <span>0:00</span>
                <span><?= Format::offset($stats['durationSeconds']) ?></span>
            </div>
            <div class="am-timeline-legend">
                <span><span class="am-legend-dot am-state-engaged"></span>Вовлечён</span>
                <span><span class="am-legend-dot am-state-distracted"></span>Отвлечён</span>
                <span><span class="am-legend-dot am-state-absent"></span>Отсутствует</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="am-card">
        <h2>Динамика вовлечённости по минутам</h2>
        <?php if ($stats['perMinute'] === []): ?>
            <p><em>Пока нет данных для графика.</em></p>
        <?php else: ?>
            <canvas id="am-chart" height="120"></canvas>
        <?php endif; ?>
    </div>
</div>
