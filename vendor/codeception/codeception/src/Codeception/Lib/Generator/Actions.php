<?php
namespace Codeception\Lib\Generator;

use Codeception\Codecept;
use Codeception\Configuration;
use Codeception\Lib\Di;
use Codeception\Lib\ModuleContainer;
use Codeception\Util\ReflectionHelper;
use Codeception\Util\Template;

class Actions
{

    protected $template = <<<EOF
<?php  //[STAMP] {{hash}}
namespace {{namespace}}_generated;

// This class was automatically generated by build task
// You should not change it manually as it will be overwritten on next build
// @codingStandardsIgnoreFile

trait {{name}}Actions
{
    /**
     * @return \Codeception\Scenario
     */
    abstract protected function getScenario();

    {{methods}}
}

EOF;

    protected $methodTemplate = <<<EOF

    /**
     * [!] Method is generated. Documentation taken from corresponding module.
     *
     {{doc}}
     * @see \{{module}}::{{method}}()
     */
    public function {{action}}({{params}}){{return_type}} {
        {{return}}\$this->getScenario()->runStep(new \Codeception\Step\{{step}}('{{method}}', func_get_args()));
    }
EOF;

    protected $name;
    protected $settings;
    protected $modules = [];
    protected $actions;
    protected $numMethods = 0;

    /**
     * @var array GeneratedStep[]
     */
    protected $generatedSteps = [];

    public function __construct($settings)
    {
        $this->name = $settings['actor'];
        $this->settings = $settings;
        $this->di = new Di();
        $modules = Configuration::modules($this->settings);
        $this->moduleContainer = new ModuleContainer($this->di, $settings);
        foreach ($modules as $moduleName) {
            $this->moduleContainer->create($moduleName);
        }
        $this->modules = $this->moduleContainer->all();
        $this->actions = $this->moduleContainer->getActions();

        $this->generatedSteps = (array) $settings['step_decorators'];
    }


    public function produce()
    {
        $namespace = rtrim($this->settings['namespace'], '\\');

        $methods = [];
        $code = [];
        foreach ($this->actions as $action => $moduleName) {
            if (in_array($action, $methods)) {
                continue;
            }
            $class = new \ReflectionClass($this->modules[$moduleName]);
            $method = $class->getMethod($action);
            $code[] = $this->addMethod($method);
            $methods[] = $action;
            $this->numMethods++;
        }

        return (new Template($this->template))
            ->place('namespace', $namespace ? $namespace . '\\' : '')
            ->place('hash', self::genHash($this->modules, $this->settings))
            ->place('name', $this->name)
            ->place('methods', implode("\n\n ", $code))
            ->produce();
    }

    protected function addMethod(\ReflectionMethod $refMethod)
    {
        $class = $refMethod->getDeclaringClass();
        $params = $this->getParamsString($refMethod);
        $module = $class->getName();

        $body = '';
        $doc = $this->addDoc($class, $refMethod);
        $doc = str_replace('/**', '', $doc);
        $doc = trim(str_replace('*/', '', $doc));
        if (!$doc) {
            $doc = "*";
        }
        $returnType = $this->createReturnTypeHint($refMethod);

        $methodTemplate = (new Template($this->methodTemplate))
            ->place('module', $module)
            ->place('method', $refMethod->name)
            ->place('return_type', $returnType)
            ->place('return', $returnType === ': void' ? '' : 'return ')
            ->place('params', $params);

        if (0 === strpos($refMethod->name, 'see')) {
            $type = 'Assertion';
        } elseif (0 === strpos($refMethod->name, 'am')) {
            $type = 'Condition';
        } else {
            $type = 'Action';
        }

        $body .= $methodTemplate
            ->place('doc', $doc)
            ->place('action', $refMethod->name)
            ->place('step', $type)
            ->produce();

        // add auto generated steps
        foreach (array_unique($this->generatedSteps) as $generator) {
            if (!is_callable([$generator, 'getTemplate'])) {
                throw new \Exception("Wrong configuration for generated steps. $generator doesn't implement \Codeception\Step\GeneratedStep interface");
            }
            $template = call_user_func([$generator, 'getTemplate'], clone $methodTemplate);
            if ($template) {
                $body .= $template->produce();
            }
        }

        return $body;
    }

    /**
     * @param \ReflectionMethod $refMethod
     * @return array
     */
    protected function getParamsString(\ReflectionMethod $refMethod)
    {
        $params = [];
        foreach ($refMethod->getParameters() as $param) {
            $type = '';
            if (PHP_VERSION_ID >= 70000) {
                $reflectionType = $param->getType();
                if ($reflectionType !== null) {
                    $type = $this->stringifyType($reflectionType, $refMethod->getDeclaringClass()) . ' ';
                }
            }

            if ($param->isOptional()) {
                $params[] = $type . '$' . $param->name . ' = ' . ReflectionHelper::getDefaultValue($param);
            } else {
                $params[] = $type . '$' . $param->name;
            }
        }
        return implode(', ', $params);
    }

    /**
     * @param \ReflectionClass $class
     * @param \ReflectionMethod $refMethod
     * @return string
     */
    protected function addDoc(\ReflectionClass $class, \ReflectionMethod $refMethod)
    {
        $doc = $refMethod->getDocComment();

        if (!$doc) {
            $interfaces = $class->getInterfaces();
            foreach ($interfaces as $interface) {
                $i = new \ReflectionClass($interface->name);
                if ($i->hasMethod($refMethod->name)) {
                    $doc = $i->getMethod($refMethod->name)->getDocComment();
                    break;
                }
            }
        }

        if (!$doc and $class->getParentClass()) {
            $parent = new \ReflectionClass($class->getParentClass()->name);
            if ($parent->hasMethod($refMethod->name)) {
                $doc = $parent->getMethod($refMethod->name)->getDocComment();
                return $doc;
            }
            return $doc;
        }
        return $doc;
    }

    public static function genHash($modules, $settings)
    {
        $actions = [];
        foreach ($modules as $moduleName => $module) {
            $actions[$moduleName] = get_class_methods(get_class($module));
        }

        return md5(Codecept::VERSION . serialize($actions) . serialize($settings['modules']) . implode(',', (array) $settings['step_decorators']));
    }

    public function getNumMethods()
    {
        return $this->numMethods;
    }

    private function createReturnTypeHint(\ReflectionMethod $refMethod)
    {
        if (PHP_VERSION_ID < 70000) {
            return '';
        }

        $returnType = $refMethod->getReturnType();

        if ($returnType === null) {
            return '';
        }

        return ': ' . $this->stringifyType($returnType, $refMethod->getDeclaringClass());
    }

    /**
     * @param \ReflectionType $type
     * @return string
     */
    private function stringifyType(\ReflectionType $type, \ReflectionClass $moduleClass)
    {
        if ($type instanceof \ReflectionUnionType) {
            return $this->stringifyNamedTypes($type->getTypes(), $moduleClass, '|');
        } elseif ($type instanceof \ReflectionIntersectionType) {
            return $this->stringifyNamedTypes($type->getTypes(), $moduleClass, '&');
        }

        if (PHP_VERSION_ID < 70100) {
            $returnTypeString = (string)$type;
        } else {
            $returnTypeString = $type->getName();
        }
        return sprintf(
            '%s%s',
            (PHP_VERSION_ID >= 70100 && $type->allowsNull() && $returnTypeString !== 'mixed') ? '?' : '',
            $this->stringifyNamedType($type, $moduleClass)
        );
    }

    /**
     * @param array<\ReflectionNamedType|\ReflectionType> $types
     * @param \ReflectionClass $moduleClass
     * @param string $separator
     * @return string
     */
    private function stringifyNamedTypes(array $types, \ReflectionClass $moduleClass, $separator)
    {
        $strings = [];
        foreach ($types as $type) {
            $strings []= $this->stringifyNamedType($type, $moduleClass);
        }

        return implode($separator, $strings);
    }

    /**
     * @param \ReflectionNamedType|\ReflectionType $type
     * @return string
     * @todo param is only \ReflectionNamedType in Codeception 5
     */
    private function stringifyNamedType($type, \ReflectionClass $moduleClass)
    {
        if (PHP_VERSION_ID < 70100) {
            $typeName = (string)$type;
        } else {
            $typeName = $type->getName();
        }

        if ($typeName === 'self') {
            $typeName = $moduleClass->getName();
        } elseif ($typeName === 'parent') {
            $typeName = $moduleClass->getParentClass()->getName();
        }

        return sprintf(
            '%s%s',
            $type->isBuiltin() ? '' : '\\',
            $typeName
        );
    }
}
