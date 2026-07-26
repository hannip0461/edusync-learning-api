<?php

declare(strict_types=1);

use EduSync\Config;
use EduSync\Database;
use EduSync\ApiErrorMiddleware;
use EduSync\BearerAuthMiddleware;
use EduSync\CorrelationIdMiddleware;
use EduSync\GuardianProgressController;
use EduSync\HealthController;
use EduSync\LearningEventController;
use EduSync\PlayerHmacAuthMiddleware;
use EduSync\ProgressRepository;
use EduSync\ProgressService;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnvironment($_ENV + $_SERVER);
$app = AppFactory::create();
$database = new Database($config);
$repository = new ProgressRepository($database);
$learningEvents = new LearningEventController(new ProgressService($repository));

$app->get('/health', new HealthController($database));
$docs = static function ($request, $response) {
    $html = file_get_contents(__DIR__ . '/swagger-ui/index.html');
    if ($html === false) {
        throw new RuntimeException('Swagger UI asset is unavailable.');
    }

    $response->getBody()->write($html);

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
};
$app->get('/docs', $docs);
$app->get('/docs/', $docs);
$app->get('/openapi.yaml', static function ($request, $response) {
    $document = file_get_contents(dirname(__DIR__) . '/openapi.yaml');
    if ($document === false) {
        throw new RuntimeException('OpenAPI document is unavailable.');
    }

    $response->getBody()->write($document);

    return $response->withHeader('Content-Type', 'application/yaml; charset=utf-8');
});
$app->post('/api/v1/learning-events', $learningEvents)
    ->add(new BearerAuthMiddleware($config, 'learner'));
$app->post('/api/v1/player-events', $learningEvents)
    ->add(new PlayerHmacAuthMiddleware($config));
$app->get('/api/v1/guardians/{guardianId}/learners/{learnerId}/progress', new GuardianProgressController($repository))
    ->add(new BearerAuthMiddleware($config, 'guardian'));

$app->add(new ApiErrorMiddleware());
$app->add(new CorrelationIdMiddleware());
$app->run();
