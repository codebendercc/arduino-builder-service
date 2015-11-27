<?php


namespace Codebender\CompilerBundle\Handler;

// This file uses mktemp() to create a temporary directory where all the files
// needed to process the compile request are stored.
require_once "System.php";
use System;
use Codebender\CompilerBundle\Handler\MCUHandler;
use Symfony\Bridge\Monolog\Logger;

class BuilderCompilerHandler
{
	private $preproc;
	private $postproc;
	private $utility;
	private $compiler_logger;
	private $object_directory;
	private $logger_id;

	function __construct(PreprocessingHandler $preprocHandl, PostprocessingHandler $postprocHandl, UtilityHandler $utilHandl, Logger $logger, $objdir)
	{
		$this->preproc = $preprocHandl;
		$this->postproc = $postprocHandl;
		$this->utility = $utilHandl;
		$this->compiler_logger = $logger;
		$this->object_directory = $objdir;
	}


	function main($request, $compiler_config)
	{
					
		error_reporting(E_ALL & ~E_STRICT);
		
				
		$start_time = microtime(true);
		
		// Step 0: Reject the request if the input data is not valid.
		$tmp = $this->requestValid($request);
		if($tmp["success"] == false)
			return $tmp;
				
		$this->setVariables($request, $format, $libraries, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid, $compiler_config);
		
		$TEMP_DIR = $compiler_config["temp_dir"];
		
		// Step 1: Extract the files and filenames included in the request.
		$filenames = array();
	
		foreach ($request["files"] as $file)
		     array_push($filenames, $file["filename"]);
		
		
		
		$files = array();
		$tmpVar = $this->extractFiles($request["files"], $TEMP_DIR, $compiler_dir, $files["sketch_files"], "files");
		if ($tmp["success"] == false)
			return $tmp;
		
		if (!array_key_exists("archive", $request) || ($request["archive"] !== false && $request["archive"] !== true))
			$ARCHIVE_OPTION = false;
		else
			$ARCHIVE_OPTION = $request["archive"];

		if ($ARCHIVE_OPTION === true)
		{
			$arch_ret = $this->createArchive($compiler_dir, $TEMP_DIR, $ARCHIVE_DIR, $ARCHIVE_PATH);
			if ($arch_ret["success"] === false)
				return $arch_ret;
		}
		
			
		$tmp = $this->doCompile($files["sketch_files"], $compiler_dir, $format, $version, $mcu, $f_cpu, $core, $variant, $pid, $vid);
		if ($tmp["success"] == false)
			return $tmp;
			
		
	
		if ($format == "syntax")
			return array(
				"success" => true,
				"time" => microtime(true) - $start_time);

		
		$tmp = $this->convertOutput($compiler_dir, $format, $SIZE, $filenames , $start_time);
	    return $tmp;
	

	}
	
	protected function convertOutput($dir, $format, $SIZE, $filenames, $start_time)
	{
		$OUTPUT = $filenames[0];  
		// To Do, Handle multiple files than just one. 
		
		if ($format == "elf")
		{
			$ret_objcopy = false;
			$content = base64_encode(file_get_contents("$dir/$OUTPUT.elf"));
			$size = filesize("$dir/$OUTPUT.elf");
		}
		elseif ($format == "binary")
		{
			$content = base64_encode(file_get_contents("$dir/$OUTPUT.hex"));
			$size = filesize("$dir/$OUTPUT.hex");
		}
		elseif ($format == "hex")
		{
			$content = file_get_contents("$dir/$OUTPUT.hex");
			$size = filesize("$dir/$OUTPUT.hex");
		}
		elseif ($format == "object")
		{
			
			$content = base64_encode(file_get_contents("$dir/sketch/$OUTPUT.cpp.o"));
			$size = filesize("$dir/sketch/$OUTPUT.cpp.o");
		}
		
		// If everything went well, return the reply to the caller.
		if ($ret_objcopy || $content === false)
			return array(
				"success" => false,
				"step" => 8,
				"message" => "There was a problem while generating the your binary file for $dir/sketch/$OUTPUT.cpp.o");
		else
			return array(
				"success" => true,
				"time" => microtime(true) - $start_time,
				"size" => $size,
				"output" => $content);

	}
	private function requestValid(&$request)
	{
		$request = $this->preproc->validateInput($request);
		if (!$request)
			return array(
				"success" => false,
				"step" => 0,
				"message" => "Invalid input.");
		else return array("success" => true);
	}
	
	private function extractFiles($request, $temp_dir, &$dir, &$files, $suffix, $lib_extraction = false)
	{
		// Create a temporary directory to place all the files needed to process
		// the compile request. This directory is created in $TMPDIR or /tmp by
		// default and is automatically removed upon execution completion.
		
		$cnt = 0;
		
		if (!$dir)
			do
			{
				$dir = @System::mktemp(" -t $temp_dir/ -d compiler");
				$cnt++;
			} while (!$dir && $cnt <= 2);
			
		if (!$dir)
			return array(
				"success" => false,
				"step" => 1,
				"message" => "Failed to create temporary directory.");
	
		$response = $this->utility->extractFiles("$dir/$suffix", $request, $lib_extraction);
		
		if ($response["success"] === false)
			return $response;
		$files = $response["files"];

		return array("success" => true);
	}
	
	
	protected function doCompile(&$files, $dir, $format, $version, $mcu, $f_cpu, $core, $variant, $pid, $vid)
	{
		$hardware = "-hardware /opt/codebender/arduino-core-files/v1.6.6/hardware -hardware /opt/codebender/arduino-core-files/v1.6.6/hardware/arduino";
		$tools = "-tools /opt/arduino-builder/tools-builder -tools /opt/codebender/arduino-core-files/v1.6.6/hardware/tools/avr";
		$libraries = "-libraries /opt/codebender/arduino-core-files/v1.6.6/libraries";
		$build_path = "-build-path $dir";
		
		if(!file_exists($dir))
			mkdir($dir);
		
		$prefs = "-prefs mcu=$mcu -prefs f_cpu=$f_cpu -prefs core=$core -prefs variant=$variant ";
		
		// If pid and vid build preferences were specified 
		if($pid != "")
		 	$prefs = $prefs."-prefs pid=$pid ";
		
		if($vid != "")
			$prefs = $prefs."-prefs vid=$vid ";
		
		$hardware = stripslashes($hardware);
		$tools = stripslashes($tools);
		$libraries = stripslashes($libraries);
		$build_path = stripslashes($build_path);
		$prefs = stripslashes($prefs);
		
		foreach (array("c", "cpp", "S","ino") as $ext)
		{
			foreach ($files[$ext] as $file)
			{
			 	// From hereon, $file is shell escaped and thus should only be used in calls
				// to exec().
				$file = escapeshellarg($file);
				
				exec("/opt/arduino-builder/arduino-builder -fqbn arduino:avr:uno  $hardware  $tools  $libraries $prefs $build_path $file.$ext 2>&1", $output, $ret_compile);
				

				
				if (isset($ret_compile) && $ret_compile)
				{
					return array(
						"success" => false,
						"step" => 4,
						"message" => $output,
						"debug" => $ret_compile);
				}
				unset($output);

				$files["o"][] = array_shift($files[$ext]);
			
			}
		}

		return array("success" => true);
	}
	
	
	private function setVariables($request, &$format, &$libraries, &$version, &$mcu, &$f_cpu, &$core, &$variant, &$vid, &$pid, &$compiler_config)
	{
		// Extract the request options for easier access.
		$format = $request["format"];
		$libraries = $request["libraries"];
		$version = $request["version"];
		$mcu = $request["build"]["mcu"];
		$f_cpu = $request["build"]["f_cpu"];
		$core = $request["build"]["core"];
		// Some cores do not specify any variants. In this case, set variant to be an empty string
		if (!array_key_exists("variant", $request["build"]))
			$variant = "";
		else
			$variant = $request["build"]["variant"];

		if ($format == "autocomplete")
		{
			$compiler_config["autocmpfile"] = $request["position"]["file"];
			$compiler_config["autocmprow"] = $request["position"]["row"];
			$compiler_config["autocmpcol"] = $request["position"]["column"];
			$compiler_config["autocmpmaxresults"] = 500;
			$compiler_config["autocmpprefix"] = $request["prefix"];
		}

		// Set the appropriate variables for vid and pid (Leonardo).

		$vid = (isset($request["build"]["vid"])) ? $request["build"]["vid"] : "null";
		$pid = (isset($request["build"]["pid"])) ? $request["build"]["pid"] : "null";
	}
}
