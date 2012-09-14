<?php 

error_reporting(E_ALL);
ini_set('display_errors','On');

class PackageStruct 
{
	public $name;
	public $classes = array();
}

class ClassStruct 
{
	public $name;
	public $extendsName;
	public $type;
	public $extends;
	public $source;
	public $members = array();
	public $properties = array();
	public $functions = array();	
	public $accessors = array();
	public $imports = array();
	public $dependencies = array();
	
	public function getScopeAccessors() 
	{
		if ($this->extends)
			return array_merge($this->accessors, $this->extends->getScopeAccessors());
		else
			return $this->accessors;
	}

	public function getScopeProperties() 
	{
		if ($this->extends)
			return array_merge($this->properties, $this->extends->getScopeProperties());
		else
			return $this->properties;
	}	

	public function getScopeFunctions() 
	{
		if ($this->extends)
			return array_merge($this->functions, $this->extends->getScopeFunctions());
		else
			return $this->functions;
	}		
	
	public function getScopeMembers() 
	{
		if ($this->extends)
			return array_merge($this->members, $this->extends->getScopeMembers());
		else
			return $this->members;
	}
	

	
}

class MemberStruct
{
	public $name;
	public $type;
	public $visibility;
	public $isPrivate;
	public $isPublic;
	public $isProtected;
	public $memberType;
	public $isVar;
	public $isConst;
	public $isFunction;

}

class PropertyStruct extends MemberStruct
{
	public $value;
}

class FunctionStruct extends MemberStruct
{
	public $source;
	public $variables = array();
	public $parameters = array();
	public $localVariables = array();
	public $scopeVariables = array();
}

class VariableStruct 
{
	public $name;
	public $type;
	public $value;
}


class Struct 
{
	public $packages = array();
	public $classes = array();
	public $members = array();
	public $methods = array();
}




function specialsplit($string) {
    $level = 0;       // number of nested sets of brackets
    $ret = array(''); // array to return
    $cur = 0;         // current index in the array to return, for convenience

    for ($i = 0; $i < strlen($string); $i++) {
        switch ($string[$i]) {
            case '(':
                $level++;
                $ret[$cur] .= '(';
                break;
            case ')':
                $level--;
                $ret[$cur] .= ')';
                break;
			case ',':
                if ($level == 0) {
                    $cur++;
                    $ret[$cur] = '';
                    break;
                }
                // else fallthrough
            default:
                $ret[$cur] .= $string[$i];
        }
    }
	
    return $ret;
}


function file_force_contents($path, $contents){
	$parts = explode('/', $path);
	array_pop($parts);
	$dir = '';
	foreach($parts as $part)
	{
		if(!is_dir($dir .= "$part/")) 
			mkdir($dir);
	}
	file_put_contents($path, $contents);
}


class As3Parser extends Struct 
{

	public function readDir($inDir, $path = '') 
	{
		if (substr($inDir, -1) != '/')
			$inDir = $inDir . '/';
			
		if ($handle = opendir($inDir.$path)) {
			echo "Directory $path <br />\n";
			echo "Files:<br />\n";
			while (false !== ($file = readdir($handle))) 
			{
				if ($file != '.' && $file != '..') 
				{
					$filePath = $path.$file;
					if (is_dir($inDir.$filePath)) 
					{
						$this->readDir($inDir, $filePath . '/');
					}
					else if (substr($inDir.$filePath, -3) == '.as') 
					{
						echo "$filePath\n";
						$this->readFile($inDir, $filePath);
					}
				}
			}
			closedir($handle);
		}		
	}
	
	public function readFile($inDir, $path) 
	{
		$source = file_get_contents($inDir.$path);

		// $regExp = '//* *//';

		$regExp = '/(?:package\s+([^\{\s]+))\s*(\{((?:[^{}]*|(?2))*)\})/';
		preg_match_all($regExp, $source, $blockMatches);  
		for ($i = 0, $size = sizeof($blockMatches[1]); $i < $size; $i++) 
		{
			$name = $blockMatches[1][$i];
			$source = $blockMatches[3][$i];
			if (!array_key_exists($name, $this->packages)) 
			{
				$packageStruct = new PackageStruct();
				$packageStruct->name = $name;
				$this->packages[$name] = $packageStruct;
			}
			else 
			{
				$packageStruct = $this->packages[$name];		
			}
			$this->parseClasses($source, $path, $packageStruct);
		}
	}
	
	public function parseImports($source, $classStruct)
	{
		$regExp = '/import\s+([^;]+);/';
		preg_match_all($regExp, $source, $blockMatches); 
		for ($i = 0, $size = sizeof($blockMatches[1]); $i < $size; $i++) {
			$classStruct->imports[] = $blockMatches[1][$i];
		}	
	}
	
	public function parseClasses($source, $path, $packageStruct) 
	{
		$regExp = '/(?:(?:private|public)\s+class\s+([^\{\s]+)(?:\s+extends\s+([^\{\s]+))?)\s*(\{((?:[^{}]*|(?3))*)\})/';
		preg_match_all($regExp, $source, $blockMatches); 
		for ($i = 0, $size = sizeof($blockMatches[1]); $i < $size; $i++) 
		{
			$classStruct = new ClassStruct();
			$classStruct->name = $blockMatches[1][$i];
			$classStruct->type = $classStruct->name;
			$classStruct->extendsName = $blockMatches[2][$i];
			$classStruct->source = $blockMatches[4][$i];
			$classStruct->packageName = $packageStruct->name;
			$classStruct->path = $path;
			$packageStruct->classes[$classStruct->name] = $classStruct;	
			$this->classes[$classStruct->name] = $classStruct;			
			$this->parseProperties($classStruct);
			$this->parseFunctions($classStruct);
			$this->parseImports($source, $classStruct);
		}
	}
	

	function parseProperties($classStruct)
	{
		//             1private|public|protected       2static      3var|const    4name               :   5type              =6new something();
		$regExp = '/(?:(private|public|protected)\s+(?:(static)\s+)?(var|const)\s+([[:alnum:]_$]+)\s*\:\s*([^;=]+)(?:=([^;]*))?;)/';
		preg_match_all($regExp, $classStruct->source, $blockMatches); 
		for ($i = 0, $size = sizeof($blockMatches[1]); $i < $size; $i++)
		{
			$memberStruct = new PropertyStruct();

			$memberStruct->visibility = $blockMatches[1][$i];
			$memberStruct->isPrivate = $memberStruct->visibility == 'private';
			$memberStruct->isPublic = $memberStruct->visibility == 'public';
			$memberStruct->isProtected = $memberStruct->visibility == 'protected';
			$memberStruct->isStatic = $blockMatches[2][$i] == 'static';
			$memberStruct->memberType = $blockMatches[3][$i];
			$memberStruct->isVar = $memberStruct->memberType == 'var';
			$memberStruct->isConst = $memberStruct->memberType == 'const';

			$memberStruct->name = $blockMatches[4][$i];
			$memberStruct->type = trim($blockMatches[5][$i]);
			$memberStruct->value = $blockMatches[6][$i];
			
			if (preg_match_all('/^Vector.<([^>]*)>$/', $memberStruct->type, $vectorMatches)) 
			{
				// $memberStruct->type = '[]';
				// $memberStruct->vectorType = $vectorMatches[1][0];
				$memberStruct->type = $vectorMatches[1][0];
				$memberStruct->isArray = true;	
			}

			$classStruct->members[$memberStruct->name] = $memberStruct;
			$classStruct->properties[$memberStruct->name] = $memberStruct;
		}
	}
	
	function parseFunctions($classStruct)
	{
		//             1private|public|protected       2static      3function       4get|set      5name          (6        )       :   7type                  8 {9          10      }   ;
		$regExp = '/(?:(private|public|protected)\s+(?:(static)\s+)?(function)(?:\s+(get|set))?\s+([^()\s]+)\s*)\(([^()]*)\)(?:\s*\:\s*([[:alnum:]_$]+))?[^{]*(\{((?:[^{}]*|(?8))*)\})/';
		preg_match_all($regExp, $classStruct->source, $blockMatches); 
		for ($i = 0, $size = sizeof($blockMatches[1]); $i < $size; $i++)
		{

			$memberStruct = new FunctionStruct();

			$memberStruct->visibility = $blockMatches[1][$i];
			$memberStruct->isPrivate = $memberStruct->visibility == 'private';
			$memberStruct->isPublic = $memberStruct->visibility == 'public';
			$memberStruct->isProtected = $memberStruct->visibility == 'protected';
			$memberStruct->isStatic = $blockMatches[2][$i] == 'static';
			$memberStruct->memberType = $blockMatches[3][$i];
			$memberStruct->isVar = false;
			$memberStruct->isConst = false;
			$memberStruct->isFunction = $memberStruct->memberType == 'function';
			$memberStruct->source = $blockMatches[9][$i];

			$memberStruct->name = $blockMatches[5][$i];		
			$memberStruct->type = trim($blockMatches[7][$i]);
			$memberStruct->isGetter = $blockMatches[4][$i] == 'get';
			$memberStruct->isSetter = $blockMatches[4][$i] == 'set';
			$memberStruct->isAccessor = $memberStruct->isGetter || $memberStruct->isSetter;
		
			$memberStruct->isConstructor = $memberStruct->name == $classStruct->name;
		
			if ($memberStruct->isAccessor) {
				$accessorStruct = new VariableStruct();
				$accessorStruct->name = $memberStruct->name;
				$accessorStruct->type = $memberStruct->type;
				$classStruct->accessors[$memberStruct->name] = $accessorStruct;
			}
			if ($memberStruct->isGetter) {
				$memberStruct->name = 'get_' . $memberStruct->name;
			}
			if ($memberStruct->isSetter) {
				$memberStruct->name = 'set_' . $memberStruct->name;
			}				

			$memberStruct->parameters = array();
			$memberStruct->parameterString = $blockMatches[6][$i];
			$parameterStringSplit = preg_split('/,/', $memberStruct->parameterString, -1, PREG_SPLIT_NO_EMPTY);
			for ($j = 0, $jsize = sizeof($parameterStringSplit); $j < $jsize; $j++) 
			{
				$parameterStruct = new VariableStruct();
				$parameterSplit = preg_split('/[:=]/', $parameterStringSplit[$j], 3);
				$parameterStruct->name = trim($parameterSplit[0]);
				$parameterStruct->type = trim($parameterSplit[1]);
				$parameterStruct->value = array_key_exists(2, $parameterSplit) ? trim($parameterSplit[2]) : '';
				$memberStruct->parameters[$parameterStruct->name] = $parameterStruct;
			}				

			$this->parseVariables2($memberStruct);
			
			$memberStruct->localVariables = array_merge($memberStruct->parameters, $memberStruct->variables);	

			$classStruct->members[$memberStruct->name] = $memberStruct;
			$classStruct->functions[$memberStruct->name] = $memberStruct;
		}	
	
	}

	
	function parseVariables2($memberStruct)
	{

		$memberStruct->variables = array();			

		$regExp = '/((?:var)|,)\s*([[:alpha:]_$][[:alnum:]_$]*)\s*\:\s*([[:alnum:]_$]+)(?:\.\<([^>]*)\>)?/';
	
		if (preg_match_all($regExp , $memberStruct->source, $varMatches)) 
		{ 
			for ($i = 0, $size = sizeof($varMatches[1]); $i < $size; $i++)
			{
				$varStruct = new VariableStruct();
				$varStruct->name = trim($varMatches[2][$i]);
				$varStruct->type = trim($varMatches[3][$i]);
				
				if ($varStruct->type == 'Vector') {
					// $varStruct->type = '[]';
					// $varStruct->vectorType = trim($varMatches[4][$i]);
					$varStruct->type = trim($varMatches[4][$i]);
					$varStruct->isArray = true;					
				}
					
				$memberStruct->variables[$varStruct->name] = $varStruct;

			}				
		}	
	
	}	
	
	

	function parseVariables($memberStruct)
	{

		$memberStruct->variables = array();			
		$regExpParts = array();
		// array_push($regExpParts, '(?:[^=;,\s]+\s*=\s*([^;,()"\']+(\(((?:[^()]*|(?2))*)\))?)+)'); // somevar = object.object(object.function().bla()).length etc
		array_push($regExpParts, '(?:[^=;,\s]+\s*=([^;,()"\']+(\(((?:[^()]*|(?2))*)\))?)+)'); // somevar = object.object(object.function().bla()).length etc
		array_push($regExpParts, '(?:[^=;,\s]+\s*=\s*("((?:(?:[^"\\\\]|\\\\.)*|(?4))*)"))'); // somevar = "blah blah\" blah"
		array_push($regExpParts, '(?:[^=;,\s]+\s*=\s*(\'((?:(?:[^\'\\\\]|\\\\.)*|(?6))*)\'))'); // somevar = 'blah blah\' blah'
		// array_push($regExpParts, '(?:[^=;,\s]+\s*=\s*\.?[[:digit:]]+(?:\.[[:digit:]]+)?)'); // somevar = .5
		array_push($regExpParts, '(?:[^=;,(){}]+\s*)'); // somevar

		$regExp = '/var\s+((?:(?:\s*,\s*)?(?:' . implode('|', $regExpParts) . '))+)/'; // var expr, expr, expr
		if (preg_match_all($regExp , $memberStruct->source, $varMatches)) 
		{ 
			foreach ($varMatches[1] as $varMatch) 
			{
				$vars = specialsplit($varMatch);

				foreach ($vars as $var) 
				{
					$varStruct = new VariableStruct();
				
					$varSplit = preg_split('/[:=]/', $var, 3);
					$varStruct->name = trim($varSplit[0]);
					$varStruct->type = trim($varSplit[1]);
					$varStruct->value = array_key_exists(2, $varSplit) ? trim($varSplit[2]) : '' ;
					
					if (preg_match_all('/^Vector.<([^>]*)>$/', $varStruct->type, $vectorMatches)) 
					{
						$varStruct->type = '[]';
						$varStruct->vectorType = $vectorMatches[1][0];
						$varStruct->isArray = true;	
					}
					
					$memberStruct->variables[$varStruct->name] = $varStruct;
				}
			}				
		}	
	
	}


	public function handleInheritance() 
	{
		foreach($this->classes as $class) 
		{
			if ($class->extendsName && array_key_exists($class->extendsName, $this->classes)) 
			{
				$class->extends = $this->classes[$class->extendsName];		
			}

			$thisVariable = new PropertyStruct();
			$thisVariable->type = $class->name;
			$thisVariable->name = 'this';
			
			foreach($class->functions as $function) 
			{
				$function->scopeVariables = array_merge($function->localVariables, $class->getScopeProperties(), array( $thisVariable ));
			}

		}	
	}
	
	
	public function setClassDependencies() 
	{
		foreach ($this->classes as $class) 
		{
			if ($class->extends) 
			{
				$class->dependencies[$class->extends->name] = $class->extends->name;
			}
			foreach ($class->imports as $importString) {
				if (strpos($importString, '*') === false)
				{
					$className = substr(strrchr($importString, '.'), 1);
					if (array_key_exists($className, $this->classes))
					{
						$class->dependencies[$className] = $className;
					}
				}
				else
				{
					foreach ($this->packages as $package) 
					{
						if (preg_match('/' . $importString . '/', $package->name)) 
						{
							foreach ($package->classes as $importClass) 
							{
								$class->dependencies[$importClass->name] = $importClass->name;
							}
						}
					}
				}
			}
		}
	}	
	
	
	public function __toString() {
		ob_start();
		foreach($this->classes as $class) 
		{
			echo "\r\n- class: " . $class->name;
			foreach ($class->members as $member) 
			{
				if ($member->isFunction) {
					$parameters = array();
					foreach ($member->parameters as $parameter) 
					{
						$parameters[] = $parameter->name . ($parameter->value ? ' = ' . $parameter->value : '');
					}
					echo "\r\n  - function " . $member->name . " ( " . implode(', ', $parameters) . " ) " . $member->type;;
					foreach ($member->variables as $variable) 
					{
						echo "\r\n    - var " . $variable->name . " " . $variable->type;
					}
				} 
				else 
				{
					echo "\r\n  - " . $member->memberType . " " . $member->name . " " . $member->type;				
				}
			}		
		}
		return ob_get_clean();
	}
}



class As3ToJSPorter {

	public $struct;
	public $namespace;

	public function __construct($struct, $namespace = 'As3') 
	{
		$this->struct = $struct;	
		$this->namespace = $namespace;
	}

	/**
	* Checks the type of $varStruct. Then searches through $source if accessor members are being used. If so rewrites them 
	*
	* @param $source 
	*   The source that we will be rewriting
	*
	* @param $varStruct
	*   The variable subject. 
	*/
	public function rewriteAccessors($source, $varStruct, $stack = array()) 
	{
		$check = $varStruct->type . '.' . $varStruct->name;
		$nextStack = array_merge($stack, array($check)); 

		// if ($varStruct->name == 'subtract' && $varStruct->type == 'Vector3D') 
			// print_r($this->struct->classes[$varStruct->type]->getScopeAccessors());
		
		if (array_key_exists($varStruct->type, $this->struct->classes)) // Check if we have a class defined for our variable
		{
			foreach ($this->struct->classes[$varStruct->type]->getScopeAccessors() as $accessor) // if so, loop through all accesors
			{
				if (isset($varStruct->isFunction) && $varStruct->isFunction) 
				{				
					$regExp = '/(' . $varStruct->name . '(\(((?:[^()]*|(?2))*)\))\.)' . $accessor->name . '(?![[:alnum:]_$])/';	
					if (preg_match($regExp, $source, $matches)) // see if function return values are using get accessors in our $source
					{
						echo "\n  - " . sizeof($stack) . ' function ' . $varStruct->name . '().' . $accessor->name . " matches property: " .  $varStruct->type . '.' . $accessor->name;
						$source = $this->rewriteAccessors($source, $accessor, $nextStack); // Our found accessor might have its own accessors
						$source = preg_replace($regExp, '$1get_' . $accessor->name . '()', $source);
					}							
				} 
				elseif (isset($varStruct->isArray) && $varStruct->isArray) 
				{
					$regExp = '/(' . $varStruct->name . '(\[((?:[^\[\]]*|(?2))*)\])\.)' . $accessor->name . '(?![[:alnum:]_$])/';	
					if (preg_match($regExp, $source, $matches)) // see if function return values are using get accessors in our $source
					{
						echo "\n  - " . sizeof($stack) . ' array ' . $varStruct->name . '[].' . $accessor->name . " matches property: " .  $varStruct->type . '.' . $accessor->name;
						$source = $this->rewriteAccessors($source, $accessor, $nextStack); // Our found accessor might have its own accessors
						$source = preg_replace($regExp, '$1get_' . $accessor->name . '()', $source);
					}											
				}
				else 
				{
					$regExp = '/(' . $varStruct->name . '\.)' . $accessor->name . '(\s*=\s*([^=;]+)\s*;)/';	
					if (preg_match($regExp, $source, $matches)) // see if we're using set accessors in our $source
					{
						echo "\n  - " . sizeof($stack) . ' setter ' . $varStruct->name . '.' . $accessor->name . " matches property: " .  $varStruct->type . '.' . $accessor->name;
						$source = preg_replace($regExp, $varStruct->name . '.set_' . $accessor->name . '($3);', $source);
					}
					$regExp = '/(' . $varStruct->name . '\.)' . $accessor->name . '(?![[:alnum:]_$])/';	
					if (preg_match($regExp, $source, $matches)) // see if we're using get accessors in our $source
					{
						echo "\n  - " . sizeof($stack) . ' getter ' . $varStruct->name . '.' . $accessor->name . " matches property: " .  $varStruct->type . '.' . $accessor->name;
						$source = $this->rewriteAccessors($source, $accessor, $nextStack); // Our found accessor might have its own accessors
						$source = preg_replace($regExp, $varStruct->name . '.get_' . $accessor->name . '()', $source);
					}								
				}
			}
			foreach ($this->struct->classes[$varStruct->type]->getScopeFunctions() as $function) // loop through all functions
			{
				$checkInStack = $function->type . '.' . $function->name;
				$regExp = '/(' . $varStruct->name . '\.)' . $function->name . '(\(((?:[^()]*|(?2))*)\))/';	
				if (preg_match($regExp, $source, $matches) && !in_array($checkInStack, $stack)) // look for functions
				{
					echo "\n  - " . sizeof($stack) . " found function: " .  $varStruct->name . '.' . $function->name . ' ' . $function->type;
					$source = $this->rewriteAccessors($source, $function, $nextStack); // Our found accessor might have its own accessors
				}			
			}
			foreach ($this->struct->classes[$varStruct->type]->getScopeProperties() as $property) // loop through all properties
			{
				$checkInStack = $property->type . '.' . $property->name;
				$regExp = '/(' . $varStruct->name . '\.)' . $property->name . '(?![[:alnum:]_$])/';
				if (preg_match($regExp, $source, $matches) && !in_array($checkInStack, $stack)) // look for properties
				{
					echo "\n  - " . sizeof($stack) . " found property: " .  $varStruct->name . '.' . $property->name . ' ' . $property->type;
					$source = $this->rewriteAccessors($source, $property, $nextStack); // Our found accessor might have its own accessors
				}			
			}
		}
		return $source;
	}
	
	public function rewriteLocalAccessors($source, $varStruct) 
	{
    $regExp = '/(?<![[:alnum:]_$\.])' . $varStruct->name . '(\s*=\s*([^;]+)\s*;)/'; 
		if (preg_match($regExp, $source, $matches)) 
		{
			echo "\n  - found local setter " . $varStruct->name;
			$source = preg_replace($regExp, 'set_' . $varStruct->name . '($2);', $source);		
		} 
		else 
		{
      $regExp = '/(?<![[:alnum:]_$\.])' . $varStruct->name . '(?![[:alnum:]_$])/'; 
			if (preg_match($regExp, $source, $matches)) 
			{
				echo "\n  - found local getter " . $varStruct->name;
				$source = preg_replace($regExp, 'get_' . $varStruct->name . '()', $source);
			}
		}
		return $source;
	}

	public function rewriteForLoops($function)
	{
		$forEachInregexp = '/for\s+each\s*\(\s*(var\s+)?([^\s]+)\s+in\s+((?:\.?([^\s\.]+))+)\s*\)/';
		$forEachInReplace = 'for (var $4_i = 0, $4_l = $3.length, $2; ($4_i < $4_l) && ($2 = $3[$4_i]); $4_i++)';
		$function->source = preg_replace($forEachInregexp, $forEachInReplace, $function->source);

		return $function;
	}

	public function removeTypes($source)
	{
		$regExp = '/((?:var)|,)\s*([[:alpha:]_$][[:alnum:]_$]*)\s*\:\s*([[:alnum:]_$]+)(\s*\.\<[^<>]*\>)?/';
		$replace = '$1 $2';
		$source = preg_replace($regExp, $replace, $source);

		$regExp = '/(?<![[:alnum:]_$\.])int\s*(\(((?:[^()]*|(?1))*)\))/';
		$replace = '$2';
		$source = preg_replace($regExp, $replace, $source);			
		
		$regExp = '/new\s+Dictionary\s*\(([^()]*)\)/';
		$replace = '[]';
		$source = preg_replace($regExp, $replace, $source);			
		
		$regExp = '/new\s+Vector\.\<[^<>]*\>\s*\(([^()]*)\)/';
		// $regExp = '/new\s+Vector\.(?<block>\<((?:[^<>]*|(?&block))*)\>)(?\s*<paren>\(((?:[^()]*|(?&paren))*)\))?/';
		$replace = '[]';
		$source = preg_replace($regExp, $replace, $source);	

		$regExp = '/Vector\.\<[^<>]*\>\s*\(([^()]*)\)/';
		// $regExp = '/new\s+Vector\.(?<block>\<((?:[^<>]*|(?&block))*)\>)(?\s*<paren>\(((?:[^()]*|(?&paren))*)\))?/';
		$replace = '[$1]';
		$source = preg_replace($regExp, $replace, $source);			
		
		//                  1block     2          3                 4paren     5          6
		$regExp = '/Vector\.(?<block>\<((?:[^<>]*|(?&block))*)\>)\s*(?<paren>\(((?:[^()]*|(?&paren))*)\))/';
		$replace = '$4';
		$source = preg_replace($regExp, $replace, $source);	
		
		// $forInregexp = '/(for\s*\(\s*(var\s+)?([^\s]*)\s+in\s+([^\s]*)\)\s*\{)/';
		// $regexp = '/(for(?:\s+(each))?\s*\(\s*(?:(var)\s+)?([^\s]*)\s+in\s+([^\s]*)\)\s*\{)/';
		// $result = array();
		// preg_match_all($regexp, $code, $blockMatches);
		// print_r($blockMatches[0]);

		return $source;
	}
	
	public function rewriteClassMembers($function, $class)
	{
		$scopeVar = $function->isStatic ? $class->name : 'this';
		foreach ($class->getScopeMembers() as $member) {
			if (isset($member->isStatic) && $member->isStatic) 
			{
				if (!array_key_exists($member->name, $function->localVariables)) 
				{
          $regExp = '/(?<![[:alnum:]_$\.])(' . $member->name . ')(?![[:alnum:]_$])/'; 
					$replace = $class->name . '.$1';
					$function->source = preg_replace($regExp, $replace, $function->source);
				}			
			}
			elseif (!isset($member->isConstructor) || !$member->isConstructor) 
			{
				// if it's not defined in our function scope
				if (!array_key_exists($member->name, $function->localVariables)) 
				{
          $regExp = '/(?<![[:alnum:]_$\.])(' . $member->name . ')(?![[:alnum:]_$])/'; 
					$replace = $scopeVar . '.$1';
					$function->source = preg_replace($regExp, $replace, $function->source);
				}
			}

		}
		return $function;
	}	
	
	public function rewriteSuperCalls($source, $class)
	{
	
		// super.postPhysics(dt);
		// jigLib.JBox.prototype.postPhysics.apply(this, arguments);	

		// super(dt);
		// jigLib.JBox.apply(this, arguments);	

		if ($class->extends) {
		
			$regExp = '/super\.([^()\.]+)(\(((?:[^()]*|(?2))*)\))/';
			// $replace = $this->namespace . '.' . $class->extends->name . '.prototype.$1.apply(this, arguments)';
			$replace = $this->namespace . '.' . $class->extends->name . '.prototype.$1.apply(this, [ $3 ])';
			$source = preg_replace($regExp, $replace, $source);

			$regExp = '/super(\(((?:[^()]*|(?1))*)\))/';
			// $replace = $this->namespace . '.' . $class->extends->name . '.apply(this, arguments)';
			$replace = $this->namespace . '.' . $class->extends->name . '.apply(this, [ $2 ])';
			$source = preg_replace($regExp, $replace, $source);

		}
		
		return $source;
	}
	

	public function removeTypeCasts($source)
	{
		$regExp = '/(\s+as\s+[[:alnum:]_$]+)/';
		$replace = '';
		$source = preg_replace($regExp, $replace, $source);
		return $source;
	}	
	

	
	
	public function port() 
	{
		foreach ($this->struct->classes as $class) 
		{
			foreach ($class->functions as $function) 
			{
				echo "\r\n- " . $class->name . ' ' . $function->name . ' ' . implode(', ', array_keys($function->localVariables));
				$function->source = $this->removeTypes($function->source);
				$this->rewriteForLoops($function);
				$function->source = $this->rewriteSuperCalls($function->source, $class);
				foreach ($function->scopeVariables as $scopeVariable) 
				{	
					$function->source = $this->rewriteAccessors($function->source, $scopeVariable);
				}				
				foreach ($this->struct->classes as $staticClass) 
				{	
					$function->source = $this->rewriteAccessors($function->source, $staticClass);
				}				
				foreach ($class->getScopeAccessors() as $accessor) 
				{	
					if (!array_key_exists($accessor->name, $function->scopeVariables))
						$function->source = $this->rewriteLocalAccessors($function->source, $accessor);
				}
				$this->rewriteClassMembers($function, $class);
				$function->source = $this->removeTypeCasts($function->source);
				// $function->source = $this->rewriteDefaultSwitchCase($function->source);	

				
			}
			foreach ($class->properties as $property) {
				 $property->value = $this->removeTypes($property->value);
			}
			
		}
	
	}
	
	
	function printNamespace()
	{
		echo "\r\n";
		echo $this->namespace . " = {};\r\n";
		echo "\r\n";
		echo $this->namespace . ".extend = function(dest, source)\r\n";
		echo "{\r\n";
		echo "\tfor (proto in source.prototype)\r\n";
		echo "\t{\r\n";
		echo "\t\tdest.prototype[proto] = source.prototype[proto];\r\n";
		echo "\t}\r\n";
		// echo "\tdest.prototype.Super = source;\r\n";
		echo "};\r\n";
		echo "\r\n";	
		echo "var trace = function(message) {};\r\n";
		echo "\r\n";
	}
	
	function namespaceToString() 
	{
		ob_start();
		$this->printNamespace();
		return ob_get_clean();
	}
	
	function printClass($class) 
	{
		echo '';
		echo "\r\n";
		echo "(function(" . $this->namespace . ") {\r\n";
		echo "\r\n";
		
		// Imports

		foreach ($this->struct->packages[$class->packageName]->classes as $siblingClass) 
		{
			if ($siblingClass->name != $class->name && !array_key_exists($siblingClass->name, $class->dependencies))
				echo "\tvar " . $siblingClass->name . ' = ' . $this->namespace . '.' . $siblingClass->name . ";\r\n";
		}		
		foreach ($class->dependencies as $dependencyName) 
		{
			echo "\tvar " . $dependencyName . ' = ' . $this->namespace . '.' . $dependencyName . ";\r\n";
		}
		
		echo "\r\n";
		
		// Constructor
		
		echo "\tvar " . $class->name . " = function(";
		if (array_key_exists($class->name, $class->functions))
			echo implode(', ', array_keys($class->functions[$class->name]->parameters));
		echo ")\r\n";
		echo "\t{";

		// Declare properties in constructor

		foreach ($class->properties as $property) 
		{
			if (!$property->isStatic) {
				echo "\r\n\t\tthis." . $property->name;
				echo " = " . ($property->value ? $property->value : "null");
				echo ";";
				echo " // " . $property->type;
			}
		}		

		// Include constructor code

		if (array_key_exists($class->name, $class->functions)) 
		{
			echo "\r\n";
			echo str_replace("\t\t\t", "\t\t", $class->functions[$class->name]->source);	
		}
		echo "\r\n\t}\r\n";	

		// Inheritance
		
		if ($class->extends) {
			echo "\r\n\t". $this->namespace .".extend(" . $class->name . ", " . $class->extends->name . ");\r\n";
		}

		// Functions
		
		foreach ($class->functions as $function) 
		{
			if (!$function->isConstructor && !$function->isStatic) 
			{
				echo "\r\n\t" . $class->name . '.prototype.' . $function->name . " = function(";
				echo implode(', ', array_keys($function->parameters));
				echo ")\r\n";
				echo "\t{";
				foreach ($function->parameters as $parameter) 
				{
					if ($parameter->value)
					{
						echo "\r\n\t\tif (" . $parameter->name . " == null) " . $parameter->name . " = " . $parameter->value . ";";
					}				
				}
				echo "\r\n";
				echo str_replace("\t\t\t", "\t\t", $function->source);
				echo "\r\n";
				echo "\t}\r\n";
			}
		}
		
		// Static members
		
		echo "\r\n";
		foreach ($class->properties as $property) 
		{
			if ($property->isStatic) 
			{
				echo "\t" . $class->name . "." . $property->name;
				echo $property->value ? " = " . $property->value : " = null " ;
				echo ";";
				echo " // " . $property->type;
				echo "\r\n";			
			} 
		}
		echo "\r\n";
		foreach ($class->functions as $function) 
		{
			if (!$function->isConstructor && $function->isStatic) 
			{
				echo "\t" . $class->name . "." . $function->name . " = function(";
				echo implode(', ', array_keys($function->parameters));
				echo ")\r\n";
				echo "\t{\r\n";
				echo $function->source;
				echo "\r\n";
				echo "\t}\r\n";
				echo "\r\n";
			}
		}
		echo "\r\n";
		echo "\t" . $this->namespace . "." . $class->name . " = " . $class->name . "; \r\n";
		echo "\r\n";
		echo "})(" . $this->namespace . ");\r\n";
		echo "\r\n";	
	}

	
	function classToString($class) 
	{
		ob_start();
		$this->printClass($class);
		return ob_get_clean();
	}

	
	public function printCombined() 
	{
		$this->printNamespace();
		foreach ($this->struct->classes as $class) 
		{
			$this->printClass($class);
		}	
	}
	
	
	public function createIncludeOrder() 
	{
		$classNames = array_keys($this->struct->classes);
		$solvedClassNames = array();
		for ($i = 0; (sizeof($classNames) > 0 && $i++ < 1000); $i++) 
		{
			$className = array_shift($classNames);
			if (sizeof(array_udiff(array_keys($this->struct->classes[$className]), $solvedClassNames)) == 0)
			{
				$solvedClassNames[] = $className;
			} 
			else
			{
				$classNames[] = $className;
			}
		}
	}

  
  public function getDependencyTree() {
  
    $result = array();
    
    $classNames = array_keys($this->struct->classes);  

    foreach ($classNames as $className) {
    
      $class = $this->struct->classes[$className];
      $result[] = array(
        'key' => $className,
        'deps' => array_values($class->dependencies)      
      );
    }
    
    return $result;
  }
  

  
	public function solveDependencies(array $unsolvedItems) {

		$solvedItems = array();
		$solvedKeys = array();
		$result = array();

		$loopCount = 0;

    $solverTrace = array();
    
		while (count($unsolvedItems) > 0) {

			if ($loopCount++ > 10000) {
				echo 'ERROR: loop limit reached';
				print_r($unsolvedItems);
				print_r($solvedItems);
				print_r($solvedKeys);
				echo implode(' ', $solverTrace);
				exit();
			}

			// get first from stack
			$subject = $unsolvedItems[0];
			$key = $subject['key'];
			$deps = $subject['deps'];
			$isSolved = true;

      $solverTrace[] = $key;
      
			// check if subject has dependencies
			if (!empty($deps)) {
				foreach ($deps as $dep) {
					// check if dependency exists in solved stack
					if (!in_array($dep, $solvedKeys)) {
						// find dependency
						$isSolved = false;
						for ($i = 0, $l = count($unsolvedItems); $i < $l; $i++) {
							if (isset($unsolvedItems[$i])) {
								$unsolvedItem = $unsolvedItems[$i];
								if ($unsolvedItem['key'] == $dep) {
									// put dependency in front of the stack
									$slice = array_splice($unsolvedItems, $i, 1);
									$depItem = $slice[0];
									array_unshift($unsolvedItems, $depItem);
									break 2;
								}
							}
						}
						echo 'Could not find dependency: \'' . $dep . '\' for \'' . $key . '\'';
						print_r($subject);
						exit();
					}
				}
			}

			// All subject dependencies exist
			if ($isSolved) {
				$solvedItems[] = array_shift($unsolvedItems);
				$solvedKeys[] = $key;
			}
		}

		return $solvedItems;
	}

	
	public function writeFiles($outDir) 
	{
		if (substr($outDir, -1) != '/')
			$outDir = $outDir . '/';

		$combined = '';
		$file = $outDir . $this->namespace . '.js';
		$contents =  $this->namespaceToString();
		file_force_contents($file, $contents);
		echo "\r\n" . htmlspecialchars('<script type="text/javascript" src="' . $file . '"></script>');
		$combined .= $contents;

    $dependencyTree = $this->solveDependencies($this->getDependencyTree());

		foreach ($dependencyTree as $dependency) 
		{
      $className = $dependency['key'];
			$class = $this->struct->classes[$className];
			$file = $outDir . str_replace(strrchr($class->path, '.'), '.js', $class->path);
			$contents = $this->classToString($class);
			file_force_contents($file, $contents);
			echo "\r\n" . htmlspecialchars('<script type="text/javascript" src="' . $file . '"></script>');
			$combined .= $contents;
		}	

		$file = $outDir . $this->namespace . '_combined.js';
		file_force_contents($file, $combined);	
				
	}

}

?>