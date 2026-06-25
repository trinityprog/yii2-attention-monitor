<?php

namespace app\modules\attentionMonitor\controllers;

use yii\web\Controller;

/**
 * Serves the page where the student sits in front of the camera. All
 * recognition happens in the browser - this controller only renders the
 * page and hands the JS the URLs of the session API.
 */
class CaptureController extends Controller
{
    public function actionIndex()
    {
        return $this->render('index');
    }
}
