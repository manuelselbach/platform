<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Twig;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

class EntityTemplateLoader implements LoaderInterface, EventSubscriberInterface
{
    /**
     * @var array
     */
    private $databaseTemplateCache = [];

    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public static function getSubscribedEvents(): array
    {
        return ['app_template.written' => 'clearInternalCache'];
    }

    public function clearInternalCache(): void
    {
        $this->databaseTemplateCache = [];
    }

    public function getSourceContext($name)
    {
        $template = $this->findDatabaseTemplate($name);

        if (!$template) {
            throw new LoaderError(sprintf('Template "%s" is not defined.', $name));
        }

        return new Source($template['template'], $name);
    }

    public function getCacheKey($name)
    {
        return $name;
    }

    public function isFresh($name, $time)
    {
        $template = $this->findDatabaseTemplate($name);
        if (!$template) {
            return false;
        }

        return $template['updatedAt'] === null || $template['updatedAt']->getTimestamp() < $time;
    }

    public function exists($name)
    {
        $template = $this->findDatabaseTemplate($name);
        if (!$template) {
            return false;
        }

        return true;
    }

    private function findDatabaseTemplate(string $name): ?array
    {
        $templateName = $this->splitTemplateName($name);
        $namespace = $templateName['namespace'];
        $path = $templateName['path'];

        if (empty($this->databaseTemplateCache)) {
            $templates = $this->connection->fetchAll('
                SELECT
                    `app_template`.`path` AS `path`,
                    `app_template`.`template` AS `template`,
                    `app_template`.`updated_at` AS `updatedAt`,
                    `app`.`name` AS `namespace`
                FROM `app_template`
                INNER JOIN `app` ON `app_template`.`app_id` = `app`.`id`
                WHERE `app_template`.`active` = 1 AND `app`.`active` = 1
            ');

            /** @var array $template */
            foreach ($templates as $template) {
                $this->databaseTemplateCache[$template['path']][$template['namespace']] = [
                    'template' => $template['template'],
                    'updatedAt' => $template['updatedAt'] ? new \DateTimeImmutable($template['updatedAt']) : null,
                ];
            }
        }

        if (array_key_exists($path, $this->databaseTemplateCache) && array_key_exists($namespace, $this->databaseTemplateCache[$path])) {
            return $this->databaseTemplateCache[$path][$namespace];
        }

        // we have already loaded all DB templates
        // if the namespace is not included return null
        return $this->databaseTemplateCache[$path][$namespace] = null;
    }

    private function splitTemplateName(string $template): array
    {
        // remove static template inheritance prefix
        if (mb_strpos($template, '@') !== 0) {
            return ['path' => $template, 'namespace' => ''];
        }

        // remove "@"
        $template = mb_substr($template, 1);

        $template = explode('/', $template);
        $namespace = array_shift($template);
        $template = implode('/', $template);

        return ['path' => $template, 'namespace' => $namespace];
    }
}
