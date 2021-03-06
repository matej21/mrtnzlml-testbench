<?php

namespace Testbench;

class Runner
{

	public function prepareArguments(array $args, $testsDir)
	{
		$args = new \Nette\Iterators\CachingIterator($args);
		$parameters = [];

		//Resolve tests dir from command line input
		$pathToTests = NULL;
		$environmentVariables = [];
		foreach ($args as $arg) {
			if (in_array($arg, ['-C', '-s', '--stop-on-fail', '-i', '--info', '-h', '--help'])) { //singles
				$parameters[$arg] = TRUE;
				continue;
			}
			if (preg_match('~^-[a-z0-9_/-]+~i', $arg)) { //remember option with value
				if (isset($parameters[$arg])) { //e.g. multiple '-w'
					$previousValue = $parameters[$arg];
					if (is_array($previousValue)) {
						$parameters[$arg][] = $args->getNextValue();
					} else {
						unset($parameters[$arg]);
						$parameters[$arg][] = $previousValue;
						$parameters[$arg][] = $args->getNextValue();
					}
				} else {
					$parameters[$arg] = $args->getNextValue();
				}
				$args->next();
			} else { //environment variables or $pathToTests
				if (preg_match('~[a-z0-9_]+=[a-z0-9_]+~i', $arg)) { //linux environment variable
					$environmentVariables[] = $arg;
				} else {
					$pathToTests = $arg;
				}
			}
		}

		//Scaffold
		if (array_key_exists('--scaffold', $parameters)) {
			if (!isset($parameters['--scaffold'])) {
				die("Error: specify tests bootstrap for scaffold like this: '--scaffold <bootstrap.php>'\n");
			}
			$scaffoldBootstrap = $parameters['--scaffold'];
			$scaffoldDir = dirname($scaffoldBootstrap);
			rtrim($scaffoldDir, DIRECTORY_SEPARATOR);
			$outputFolderContent = glob("$scaffoldDir/*");
			if (($key = array_search($scaffoldBootstrap, $outputFolderContent)) !== FALSE) {
				unset($outputFolderContent[$key]);
			}
			if (count($outputFolderContent) !== 0) {
				die("Error: please use different empty folder - I don't want to destroy your work\n");
			}
			require $scaffoldBootstrap;
			\Nette\Utils\FileSystem::createDir($scaffoldDir . '/_temp');
			$scaffold = new \Testbench\Scaffold\TestsGenerator;
			$scaffold->generateTests($scaffoldDir);
			\Tester\Environment::$checkAssertions = FALSE;
			die("Tests generated to the folder '$scaffoldDir'\n");
		}

		//Specify PHP interpreter to run
		if (!array_key_exists('-p', $parameters)) {
			$parameters['-p'] = 'php';
		}

		//Show information about skipped tests
		if (!array_key_exists('-s', $parameters)) {
			$parameters['-s'] = TRUE;
		}

		//Look for php.ini file
		$os = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'win' : 'unix';
		$iniFile = $testsDir . "/php-$os.ini";
		if (!array_key_exists('-c', $parameters)) {
			if (is_file($iniFile)) {
				$parameters['-c'] = $iniFile;
			} else {
				$parameters['-C'] = TRUE;
			}
		}

		//Purge temp directory
		if (isset($parameters['--temp'])) {
			$dir = $parameters['--temp'];
		} else {
			$dir = $testsDir . '/_temp';
		}
		unset($parameters['--temp']);
		if (!is_dir($dir)) {
			mkdir($dir);
		}
		$rdi = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
		$rii = new \RecursiveIteratorIterator($rdi, \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($rii as $entry) {
			if ($entry->isDir()) {
				rmdir($entry);
			} else {
				unlink($entry);
			}
		}

		if ($pathToTests === NULL) {
			$pathToTests = $testsDir;
		}

		$args = $environmentVariables;
		foreach ($parameters as $key => $value) { //return to the Tester format
			if ($value === TRUE) { //singles
				$args[] = $key;
				continue;
			}
			if (is_array($value)) {
				foreach ($value as $v) {
					$args[] = $key;
					$args[] = $v;
				}
			} else {
				$args[] = $key;
				$args[] = $value;
			}
		}
		$args[] = $pathToTests;
		return $args;
	}

	public function findVendorDirectory()
	{
		$recursionLimit = 10;
		$findVendor = function ($dirName = 'vendor/bin', $dir = __DIR__) use (&$findVendor, &$recursionLimit) {
			if (!$recursionLimit--) {
				throw new \Exception('Cannot find vendor directory.');
			}
			$found = $dir . "/$dirName";
			if (is_dir($found) || is_file($found)) {
				return dirname($found);
			}
			return $findVendor($dirName, dirname($dir));
		};
		return $findVendor();
	}

}
