<?php

namespace app\modules\attentionMonitor;

/**
 * Attention Monitor module: tracks student engagement during a lesson via
 * client-side webcam analysis and renders a post-lesson report.
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\attentionMonitor\controllers';

    /**
     * Weight applied to "distracted" seconds when computing the 0-100
     * engagement score. Engaged seconds always count fully, absent seconds
     * never count. See services/EngagementCalculator.php.
     */
    public $distractedWeight = 0.5;

    /**
     * Minimum number of consecutive seconds without a detected face before
     * the client reports the "absent" state, per spec.
     */
    public $absenceThresholdSeconds = 2;

    public function init()
    {
        parent::init();
    }
}
