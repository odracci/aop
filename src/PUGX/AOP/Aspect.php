<?php

namespace PUGX\AOP;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\FileCacheReader;
use Doctrine\Common\Annotations\Reader;
use ReflectionClass;
use Doctrine\Common\Annotations\AnnotationRegistry;
use PUGX\AOP\Aspect\AspectInterface;
use ReflectionMethod;
use PUGX\AOP\DependencyInjection\Compiler;
use PUGX\AOP\DependencyInjection\Service;
use PUGX\AOP\Aspect\Annotation;

/**
 * Base class that provides a common behavior for all aspects that you want to
 * inject in your code.
 */
abstract class Aspect implements AspectInterface
{
    protected $namespacesToImport;
    protected $annotationsReader;
    protected $proxyDirectory;
    protected $service;
    
    /**
     * Constructor
     * 
     * @param string $proxyDirectory
     * @param \Doctrine\Common\Annotations\Reader $annotationsReader
     */
    public function __construct($proxyDirectory, Reader $annotationsReader = null)
    {
        $this->proxyDirectory = $proxyDirectory;
        $this->annotationsReader = new FileCacheReader(
            new AnnotationReader(),
            $this->getProxyDirectory(),
            $debug = true
        );
    }
    
    /**
     * @inheritdoc
     */
    public function attach($class, Service $service)
    {
        $this->service          = $service;

        $refClass               = new ReflectionClass($class);
        $namespace              = $refClass->getNamespaceName();
        $proxyClassName         = "PUGX\AOP\Proxy\\" . $refClass->getName();
        $proxyClassNamespace    = "PUGX\AOP\Proxy\\" . $refClass->getNamespaceName();
        $proxyClassPath         = $this->getProxyDirectory() . str_replace('\\', '/', $proxyClassName) . ".php";
        $proxyClassDir          = $this->getProxyDirectory() . str_replace('\\', '/', $proxyClassNamespace);
        $proxyClassShortName    = $refClass->getShortName();
        $methods                = $this->generateMethods($refClass);
        $proxy                  = <<<EOT
<?php

namespace $proxyClassNamespace;
            
$this->namespacesToImport

class $proxyClassShortName extends \\$class
{
    $methods
}
EOT;

        if (!is_dir($proxyClassDir)) {
            mkdir($proxyClassDir, 0777, true);
        }
        
        file_put_contents($proxyClassPath, $proxy);
        
        return $proxyClassName;
    }
    
    /**
     * Returns the annotations reader.
     * 
     * @return Doctrine\Common\Annotations\Reader
     */
    protected function getAnnotationsReader()
    {
        return $this->annotationsReader;
    }
    
    /**
     * Returns the directory where proxy classes will be stored.
     * 
     * @return string
     */
    protected function getProxyDirectory()
    {
        return $this->proxyDirectory;
    }
    
    /**
     * Generates methods of the proxy class.
     * The single method's body will be generated by the concrete aspect class.
     * @see PUGX\AOP\Aspect\Loggable
     * 
     * @param ReflectionClass $refClass
     * @return string
     */
    protected function generateMethods(ReflectionClass $refClass)
    {
        $methods = "";

        foreach ($refClass->getMethods() as $refMethod) {
                $parameters         = array();
                $parentParameters   = array();

                foreach ($refMethod->getParameters() as $refParameter) {
                    if ($refParameter->getClass()) {
                        $this->namespacesToImport .= <<<EOT
use {$refParameter->getClass()->getName()};
EOT;

                        $type = $refParameter->getClass()->getShortName();
                    } elseif ($refParameter->isArray()) {
                        $type = 'array';
                    } else {
                        $type = null;
                    }
                    
                    $parameter              = $type . " $" . $refParameter->getName();
                    
                    if ($refParameter->isOptional()) {
                        $parameter .= ' = array()';
                    }
                    
                    $parameters[]           = $parameter;
                    $parentParameters[]     = "$" . $refParameter->getName();
                }

                $parametersAsString         = implode(', ', $parameters);
                $parentParametersAsString   = implode(', ', $parentParameters);

                $methods .= $this->generateMethod($refMethod, $parametersAsString, $parentParametersAsString);
            }

        return $methods;
    }
    
    /**
     * Generates the AOP code to be inserted in the $stage of the $refMethod.
     * 
     * @param string $stage
     * @param ReflectionMethod $refMethod
     */
    abstract protected function generateAspectCodeAtMethodStage($stage, ReflectionMethod $refMethod);
    
    /**
     * Gets the dependencies that need to be injected in the new service's
     * constructor.
     * 
     * @return array
     */
    abstract protected function getDependencies();
    
    /**
     * Generates the content of the method in the proxy class.
     * 
     * @param ReflectionMethod $refMethod
     * @param array $parameters
     * @param array $parentParameters
     * @return string
     */
    protected function generateMethod(ReflectionMethod $refMethod, $parameters, $parentParameters)
    {
        $scope                          = $refMethod->isStatic() ? 'static' : null;
        $visibility                     = $refMethod->isPublic() ? 'public' : 'protected';
        $servicesToAddInTheSignature    = null;
        $servicesToSetInTheBody         = null;

        if ($refMethod->isConstructor()) {           
            $servicesToAddInTheSignature    = array();
            $servicesToSetInTheBody         = array();
            
            foreach ($this->getDependencies() as $dependency) {
                $servicesToAddInTheSignature[]  = "\$" . $dependency . " = null";
                $servicesToSetInTheBody[]       = "\$this->{$dependency} = \${$dependency};";
            }
            
            $servicesToAddInTheSignature    = implode(',', $servicesToAddInTheSignature);
            $servicesToSetInTheBody         = implode(';', $servicesToSetInTheBody);
            
            if (strlen($parameters)) {
                $servicesToAddInTheSignature = ", " . $servicesToAddInTheSignature;
            }
        }
        
        return <<<EOT
    $visibility $scope function {$refMethod->getName()}($parameters $servicesToAddInTheSignature)
    {
        $servicesToSetInTheBody
        {$this->generateAspectCodeAtMethodStage(Annotation::START, $refMethod)}
        \$result = parent::{$refMethod->getName()}($parentParameters);    
        {$this->generateAspectCodeAtMethodStage(Annotation::END, $refMethod)}
        
        return \$result;
    }

EOT;
    }
    
    /**
     * Retrieves all the annotations for the current aspect.
     * This is a convenient method since you might end up having different
     * annotations in a method and when an aspect is processing a method it only
     * wants to deal with the annotations that are meaningful to it.
     * 
     * @param ReflectionMethod $refMethod
     * @return array
     */
    protected function getAspectAnnotations(ReflectionMethod $refMethod)
    {
        $annotations        = $this->getAnnotationsReader()->getMethodAnnotations($refMethod);
        $annotationsClass   = $this->getAnnotationsClass();
        
        foreach ($annotations as $key => $annotation) {
            if (!$annotation instanceOf $annotationsClass) {
                unset($annotations[$key]);
            }
        }
        
        return $annotations;
    }
    
    /**
     * Returns the class that is used to read annotations for the current
     * aspect.
     * 
     * @return string
     */
    protected function getAnnotationsClass()
    {
        $refClass = new \ReflectionClass($this);
        
        return $refClass->getName() . "\Annotation";
    }
    
    /**
     * Returns the compiler which is handling AOP in the current container.
     * 
     * @return PUGX\AOP\DependencyInjection\Service
     */
    protected function getService()
    {
        return $this->service;
    }
}