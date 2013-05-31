<?php

namespace PUGX\AOP\Stub;

use PUGX\AOP\Aspect\Loggable\Annotation as Log;
use \stdClass;

class MyClass
{
    protected $a;
    
    /**
     * @Log(what="$a", when="start", with="monolog.logger_standard", as="Lets log a, which is %s")
     * @Log(what="$b", when="end", with="monolog.logger_standard", as="$b is %s")
     * @Log(what="$this->a", when="end", with="monolog.logger_standard", as="MyClass::a, which is the sum of a and b, is %s")
     * @PUGX\AOP\Stub\Annotation\Test
     * @param int $a
     * @param int $b
     */
    public function __construct($a, $b)
    {
        $this->a = $a + $b;
    }
    
    public function some(stdClass $o)
    {
        
    }
}