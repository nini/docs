<?php

/**
 * This scripts generates the restructuredText for the class API.
 *
 * Change the CPHALCON_DIR constant to point to the dev/ directory in the Phalcon source code
 *
 * php scripts/gen-api.php
 */

define('CPHALCON_DIR', '/Applications/MAMP/htdocs/phalcon/target/dev/');

class API_Generator {

	protected $_docs = array();

	public function __construct($directory){
		$this->_scanSources($directory);
	}

	protected function _scanSources($directory){
		$iterator = new DirectoryIterator($directory);
		foreach($iterator as $item){
			if($item->isDir()){
				if($item->getFileName()!='.'&&$item->getFileName()!='..'){
					$this->_scanSources($item->getPathname());
				}
			} else {
				if(preg_match('/\.c$/', $item->getPathname())){
					if(strpos($item->getPathname(), 'kernel')===false){
						$this->_getDocs($item->getPathname());
					}
				}
			}
		}
	}

	protected function _getDocs($file){
		$firstDoc = true;
		$openComment = false;
		$nextLineMethod = false;
		$comment = '';
		foreach(file($file) as $line){
			if(trim($line)=='/**'){
				$openComment = true;
				$comment.=$line;
			}
			if($openComment===true){
				$comment.=$line;
			} else {
				if($nextLineMethod===true){
					if(preg_match('/^PHP_METHOD\(([a-zA-Z\_]+), (.*)\)/', $line, $matches)){
						$this->_docs[$matches[1]][$matches[2]] = $comment;
						$className = $matches[1];
					} else {
						if($firstDoc===true){
							$classDoc = $comment;
							$firstDoc = false;
							$comment = '';
						}
					}
					$nextLineMethod = false;
				} else {
					$comment = '';
				}
			}
			if($openComment===true){
				if(trim($line)=='*/'){
					$comment.=$line;
					$openComment = false;
					$nextLineMethod = true;
				}
			}
		}
		if(isset($classDoc)){
			if(isset($className)){
				$this->_classDocs[$className] = $classDoc;
			}
		}
	}

	public function getDocs(){
		return $this->_docs;
	}

	public function getClassDocs(){
		return $this->_classDocs;
	}

	public function getPhpDoc($phpdoc, $className, $methodName, $realClassName){

		$ret = array();
		$lines = array();
		$description = '';
		$phpdoc = trim($phpdoc);
		foreach(explode("\n", $phpdoc) as $line){
			$line = preg_replace('#^/\*\*#', '', $line);
			$line = str_replace('*/', '', $line);
			$line = preg_replace('#^[ \t]+\*#', '', $line);
			$tline = trim($line);
			if($className!=$tline){
				$lines[] = $line;
			}
		}

		$rc = str_replace("\\\\", "\\", $realClassName);

		$numberBlock = -1;
		$insideCode = false;
		$codeBlocks = array();
		foreach($lines as $line){
			if(strpos($line, '<code')!==false){
				$numberBlock++;
				$insideCode = true;
			}
			if(strpos($line, '</code')!==false){
				$insideCode = false;
			}
			if($insideCode==false){
				$line = str_replace('</code>', '', $line);
				if(trim($line)!=$rc){
					if(preg_match('/@([a-z0-9]+)/', $line, $matches)){
						$content = trim(str_replace($matches[0], '', $line));
						if($matches[1]=='param'){
							$parts = preg_split('/[ \t]+/', $content);
							if(count($parts)!=2){
								throw new Exception("Failed proccessing parameters in ".$className.'::'.$methodName);
							}
							$ret['parameters'][$parts[1]] = trim($parts[0]);
						} else {
							$ret[$matches[1]] = $content;
						}
					} else {
						$description.=ltrim($line)."\n";
					}
				}
			} else {
				if(!isset($codeBlocks[$numberBlock])){
					$line = str_replace('<code>', '', $line);
					$codeBlocks[$numberBlock] = $line."\n";
					$description.='%%'.$numberBlock.'%%';
				} else {
					$codeBlocks[$numberBlock].=$line."\n";
				}
			}
		}

		foreach($codeBlocks as $n => $cc){
			$c = '';
			$firstLine = true;
			$p = explode("\n", $cc);
			foreach($p as $pp){
				if($firstLine){
					if(substr(ltrim($pp), 0, 1)!='['){
						if(!preg_match('#^<?php#', ltrim($pp))){
							if(count($p)==1){
								$c.='    <?php ';
							} else {
								$c.='    <?php'.PHP_EOL.PHP_EOL;
							}
						}
					}
					$firstLine = false;
				}
				$pp = preg_replace('#^\t#', '', $pp);
				if(count($p)!=1){
					$c.='    '.$pp.PHP_EOL;
				} else {
					$c.= $pp.PHP_EOL;
				}
			}
			$c.=PHP_EOL;
			$codeBlocks[$n] = rtrim($c);
		}

		$description = str_replace('<p>', '', $description);
		$description = str_replace('</p>', PHP_EOL.PHP_EOL, $description);

		$c = $description;
		$c = str_replace("\\", "\\\\", $c);
		$c = trim(str_replace("\t", "", $c));
		$c = trim(str_replace("\n", " ", $c));
		foreach($codeBlocks as $n => $cc){
			if(preg_match('#\[[a-z]+\]#', $cc)){
				$type = 'ini';
			} else {
				$type = 'php';
			}
			$c = str_replace('%%'.$n.'%%', PHP_EOL.PHP_EOL.'.. code-block:: '.$type.PHP_EOL.PHP_EOL.$cc.PHP_EOL.PHP_EOL, $c);
		}

		$final = '';
		$blankLine = false;
		foreach(explode("\n", $c) as $line){
			if(trim($line)==''){
				if($blankLine==false){
					$final.=$line."\n";
					$blankLine = true;
				}
			} else {
				$final.=$line."\n";
				$blankLine = false;
			}
		}

		$ret['description'] = $final;
		return $ret;
	}

}

$index = 'API Indice
-----------------

.. toctree::
   :maxdepth: 1'.PHP_EOL.PHP_EOL;

$api = new API_Generator(CPHALCON_DIR);

$classDocs = $api->getClassDocs();
$docs = $api->getDocs();

foreach(get_declared_classes() as $className){

	if (!preg_match('#^Phalcon#', $className)) {
		continue;
	}

	$simpleClassName = str_replace("\\", "_", $className);
	if (isset($docs[$simpleClassName])) {
		$docMethods = $docs[$simpleClassName];
	} else {
		$docMethods = array();
	}

	$reflector = new ReflectionClass($className);

	$documentationData = array();

	$typeClass = 'public';
	if ($reflector->isAbstract() == true) {
		$typeClass = 'abstract';
	}
	if ($reflector->isFinal() == true) {
		$typeClass = 'final';
	}

	$documentationData = array(
		'type'			=> $typeClass,
		'description'	=> $realClassName,
		'extends'		=> $reflector->getParentClass(),
		'implements'	=> $reflector->getInterfaceNames(),
		'constants'     => $reflector->getConstants(),
		'methods'		=> $reflector->getMethods()
	);

	$index.='   '.$simpleClassName.PHP_EOL;

	$nsClassName = str_replace("\\", "\\\\", $className);

	$code = 'Class **'.$nsClassName.'**'.PHP_EOL;
	$code.= str_repeat("=", strlen($nsClassName)+10).PHP_EOL.PHP_EOL;

	if($documentationData['extends']){
		$extendsName = $documentationData['extends']->name;
		if(strpos($extendsName, 'Phalcon')!==false){
			$extendsPath =  str_replace("\\", "_", $extendsName);
			$extendsName =  str_replace("\\", "\\\\", $extendsName);
			$code.='*extends* :doc:`'.$extendsName.' <'.$extendsPath.'>`'.PHP_EOL.PHP_EOL;
		} else {
			$code.='*extends* '.$extendsName.PHP_EOL.PHP_EOL;
		}
	}

	//Generate the interfaces part
	if(count($documentationData['implements'])){
		$code.='*implements* '.join(', ', $documentationData['implements']).PHP_EOL.PHP_EOL;
	}

	if(isset($classDocs[$className])){
		$ret = $api->getPhpDoc($classDocs[$className], $className, null, $realClassName);
		$code.= $ret['description'].PHP_EOL.PHP_EOL;
	}

	if(count($documentationData['constants'])){
		$code.='Constants'.PHP_EOL;
		$code.='---------'.PHP_EOL.PHP_EOL;
		foreach($documentationData['constants'] as $name => $constant){
			$code.= gettype($constant).' **'.$name.'**'.PHP_EOL.PHP_EOL;
		}
	}

	if(count($documentationData['methods'])){

		$code.='Methods'.PHP_EOL;
		$code.='---------'.PHP_EOL.PHP_EOL;
		foreach($documentationData['methods'] as $method){
			if(isset($docMethods[$method->name])){
				$ret = $api->getPhpDoc($docMethods[$method->name], $className, $method->name, null);
			} else {
				$ret = array();
			}
			//$code.='.. method:: ';
			if(isset($ret['return'])){
				if(strpos($ret['return'], 'Phalcon')!==false){
					$extendsPath =  str_replace("\\", "_", $ret['return']);
					$extendsName =  str_replace("\\", "\\\\", $ret['return']);
					$code.=':doc:`'.$extendsName.' <'.$extendsPath.'>` ';
				} else {
					$code.= '*'.$ret['return'].'* ';
				}
			}
			$code.= implode(' ', Reflection::getModifierNames($method->getModifiers())).' **'.$method->name.'** (';

			$cp = array();
			foreach($method->getParameters() as $parameter){
				$name = '$'.$parameter->name;
				if(isset($ret['parameters'][$name])){
					$cp[] = '*'.$ret['parameters'][$name].'* '.$name;
				} else {
					$cp[] = '*unknown* '.$name;
				}
			}
			$code.=join(', ', $cp).')'.PHP_EOL.PHP_EOL;

			if(isset($ret['description'])){
				foreach(explode("\n", $ret['description']) as $dline){
					$code.="".$dline."\n";
				}
				$code.=PHP_EOL.PHP_EOL;
			}

		}

	}

	file_put_contents('en/api/'.$simpleClassName.'.rst', $code);
}

file_put_contents('en/api/index.rst', $index);

