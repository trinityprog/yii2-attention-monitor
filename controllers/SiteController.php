<?php

declare(strict_types=1);

namespace app\controllers;

use yii\web\Controller;
use yii\web\ErrorAction;
use yii\web\Response;

class SiteController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function actions(): array
    {
        return [
            'error' => [
                'class' => ErrorAction::class,
            ],
        ];
    }

    /**
     * The app has a single purpose - redirect straight to the lesson page.
     */
    public function actionIndex(): Response
    {
        return $this->redirect(['/attention-monitor/capture/index']);
    }
}
