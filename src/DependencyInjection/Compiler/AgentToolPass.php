<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use App\Tool\Attribute\AgentTool;
use App\Tool\Attribute\AgentToolParam;
use App\Tool\ToolRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Descubre las herramientas tagueadas (app.agent_tool), lee sus atributos
 * #[AgentTool] / #[AgentToolParam] por reflexion y genera UNA SOLA VEZ el JSON
 * Schema de cada una para el campo `tools` de la API de Messages (ADR 4). Inyecta
 * en ToolRegistry el set canonico + un service locator para ejecutarlas.
 *
 * CRITICO (Cambio 4): este pass es AGNOSTICO DEL NIVEL. No lee LAB_LEVEL ni ningun
 * parametro derivado — en tiempo de compilacion recibiria el placeholder, no el
 * valor, y un cambio de nivel sin limpiar cache dejaria schemas obsoletos en
 * silencio. Genera SIEMPRE el conjunto completo; el filtrado por nivel es runtime.
 */
final class AgentToolPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ToolRegistry::class)) {
            return;
        }

        $definitions = [];
        $locator = [];

        foreach ($container->findTaggedServiceIds('app.agent_tool') as $id => $tags) {
            $class = $container->getDefinition($id)->getClass() ?? $id;
            $reflection = new \ReflectionClass($class);

            $toolAttribute = ($reflection->getAttributes(AgentTool::class)[0] ?? null)?->newInstance();
            if (!$toolAttribute instanceof AgentTool) {
                throw new \LogicException(sprintf(
                    'El servicio "%s" lleva el tag app.agent_tool pero le falta el atributo #[AgentTool].',
                    $id,
                ));
            }

            $properties = [];
            $required = [];
            foreach ($reflection->getAttributes(AgentToolParam::class) as $paramAttribute) {
                /** @var AgentToolParam $param */
                $param = $paramAttribute->newInstance();

                $property = ['type' => $param->type];
                if ('' !== $param->description) {
                    $property['description'] = $param->description;
                }
                if (null !== $param->enum) {
                    $property['enum'] = $param->enum;
                }

                $properties[$param->name] = $property;
                if ($param->required) {
                    $required[] = $param->name;
                }
            }

            $inputSchema = ['type' => 'object', 'properties' => $properties];
            if ([] !== $required) {
                $inputSchema['required'] = $required;
            }

            $definitions[$toolAttribute->name] = [
                'name' => $toolAttribute->name,
                'description' => $toolAttribute->description,
                'input_schema' => $inputSchema,
            ];
            $locator[$toolAttribute->name] = new Reference($id);
        }

        // Orden determinista (cache de prompt / reproducibilidad del harness).
        ksort($definitions);

        $registry = $container->getDefinition(ToolRegistry::class);
        $registry->setArgument('$definitions', $definitions);
        $registry->setArgument('$tools', ServiceLocatorTagPass::register($container, $locator));
    }
}
