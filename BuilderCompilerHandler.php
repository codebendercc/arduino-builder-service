<?php


namespace Codebender\CompilerBundle\Handler;

// This file uses mktemp() to create a temporary directory where all the files
// needed to process the compile request are stored.
require_once "System.php";
use System;
use Codebender\CompilerBundle\Handler\MCUHandler;
use Codebender\CompilerBundle\Handler\CompilerHandler;

class BuilderCompilerHandler
{

	private $preproc;
	private $postproc;
	private $utility;

	function __construct()
	{
		$this->preproc = new PreprocessingHandler();
		$this->postproc = new PostprocessingHandler();
		$this->utility = new UtilityHandler();
	}

	/**
	\brief Processes a compile request.

	\param string $request The body of the POST request.
	\return A message to be JSON-encoded and sent back to the requestor.
	 */
	function main($request, $compiler_config)
	{
					
		error_reporting(E_ALL & ~E_STRICT);

		$start_time = microtime(true);
		
		
		// Step 0: Reject the request if the input data is not valid.
		//TODO: Replace $tmp variable name
		$tmp = $this->requestValid($request);
		if($tmp["success"] == false)
			return $tmp;

		$this->set_variables($request, $format, $libraries, $version, $mcu, $f_cpu, $core, $variant, $vid, $pid);

		// Step 1: Extract the files and filenames included in the request.
		$filenames = array();

		foreach ($request->files as $file)
		     array_push($filenames, $file->filename);
		
		
		
		$tmp = $this->extractFiles($request, $dir, $files);
		if ($tmp["success"] == false)
			return $tmp;
		     
		
		$tmp = $this->doCompile($files, $dir, $format, $version, $mcu, $f_cpu, $core, $variant, $pid, $vid);
		if ($tmp["success"] == false)
			return $tmp;

		if ($format == "syntax")
			return array(
				"success" => true,
				"time" => microtime(true) - $start_time);

		$build_path = '/tmp/codebender_object_files';
		$tmp = $this->convertOutput($build_path, $format, $SIZE, $filenames , $start_time);
	    return $tmp;
		

	}
	
	
    private function requestValid(&$request)
	{
		$request = $this->preproc->validate_input($request);
		if (!$request)
			return array(
				"success" => false,
				"step" => 0,
				"message" => "Invalid input.");
		else return array("success" => true);
	}
	
	protected function extractFiles($request, &$dir, &$files)
	{
		// Create a temporary directory to place all the files needed to process
		// the compile request. This directory is created in $TMPDIR or /tmp by
		// default and is automatically removed upon execution completion.
		$dir = "/tmp/compiler";
		
		mkdir($dir);

		if (!$dir)
			return array(
				"success" => false,
				"step" => 1,
				"message" => "Failed to create temporary directory.");
		
		
		
		
		$response = $this->utility->extract_files($dir, $request->files);
		
		if ($response["success"] === false)
			return $response;
		$files = $response["files"];

		if (!file_exists($dir."/libraries"))
			mkdir($dir."/libraries/", 0777, true);
		//TODO: check if it succeeded
		$files["libs"] = array();
		foreach($request->libraries as $library_name => $library_files)
		{
			//TODO: check if it succeeded
			if (!file_exists($dir."/libraries".$library_name))
				mkdir($dir."/libraries/".$library_name, 0777, true);
			$tmp = $this->utility->extract_files($dir."/libraries/".$library_name, $library_files);
			$files["libs"][] = $tmp["files"];
		}

		return array("success" => true);
	}



	protected function doCompile(&$files, $dir, $format, $version, $mcu, $f_cpu, $core, $variant, $pid, $vid)
	{
		$hardware = "-hardware /home/vagrant/work/src/arduino.cc/builder/hardware -hardware /opt/codebender/codebender-arduino-core-files/v106/hardware -hardware /opt/codebender/codebender-arduino-core-files/v106/hardware/arduino/ -hardware /opt/codebender/codebender-arduino-core-files/v106/hardware/tools/";
		$tools = "-tools /usr/bin -tools /opt/codebender/codebender-arduino-core-files/v106/hardware/tools/avr  -tools /opt/codebender/codebender-arduino-core-files/v106/tools-builder";
		$libraries = "-libraries /opt/codebender/codebender-arduino-core-files/v106/libraries -libraries /opt/codebender/codebender-arduino-core-files/v106/hardware/arduino/avr/libraries -libraries /tmp/compiler/libraries ";
		$build_path = "-build-path /tmp/codebender_object_files";
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
				exec("/home/vagrant/work/bin/builder -fqbn arduino:avr:uno  $hardware  $tools  $libraries $prefs $build_path $file.$ext 2>&1", $output, $ret_compile);
				
				
				if (isset($ret_compile) && $ret_compile)
				{
					return array(
						"success" => false,
						"step" => 4,
						"message" => $output,
						"debug" => $avr_output);
				}
				unset($output);

				$files["o"][] = array_shift($files[$ext]);
			
			}
		}

		return array("success" => true);
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

	private function preprocessIno(&$files, $ARDUINO_CORES_DIR, $ARDUINO_SKEL, $version, $core)
	{
		foreach ($files["ino"] as $file)
		{
			//TODO: make it compatible with non-default hardware (variants & cores)
			if (!isset($skel) && ($skel = file_get_contents("$ARDUINO_CORES_DIR/v$version/hardware/arduino/cores/$core/$ARDUINO_SKEL")) === false)
				return array(
					"success" => false,
					"step" => 2,
					"message" => "Failed to open Arduino skeleton file.");

			$code = file_get_contents("$file.ino");
			$new_code = $this->preproc->ino_to_cpp($skel, $code, "$file.ino");
			$ret = file_put_contents("$file.cpp", $new_code);

			if ($code === false || !$new_code || !$ret)
				return array(
					"success" => false,
					"step" => 2,
					"message" => "Failed to preprocess file '$file.ino'.");

			$files["cpp"][] = array_shift($files["ino"]);
		}

		return array("success" => true);
	}

	public function preprocessHeaders(&$files, &$libraries, &$include_directories, $dir, $ARDUINO_CORES_DIR, $version, $core, $variant)
	{
		try
		{
			// Create command-line arguments for header search paths. Note that the
			// current directory is added to eliminate the difference between <>
			// and "" in include preprocessor directives.
			//TODO: make it compatible with non-default hardware (variants & cores)
			$include_directories = "-I$dir -I$ARDUINO_CORES_DIR/v$version/hardware/arduino/cores/$core -I$ARDUINO_CORES_DIR/v$version/hardware/arduino/variants/$variant";

			//TODO: The code that rests on the main website looks for headers in all files, not just c, cpp and h. Might raise a security issue
			$files["dir"] = array();
			foreach($libraries as $library_name => $library_files)
			{
				$files["dir"][] = $dir."/libraries/".$library_name;
			}

			// Add the libraries' paths in the include paths in the command-line arguments
			if (file_exists("$dir/utility"))
				$include_directories .= " -I$dir/utility";
			foreach ($files["dir"] as $directory)
				$include_directories .= " -I$directory";
		}
		catch(\Exception $e)
		{
			return array("success" => false, "step" => 3, "message" => "Unknown Error:\n".$e->getMessage());
		}

		return array("success" => true);
	}
    /*private function set_values($compiler_config,
	                            &$CC, &$CPP, &$AS, &$LD, &$CLANG, &$OBJCOPY, &$SIZE, &$CFLAGS, &$CPPFLAGS,
	                            &$ASFLAGS, &$LDFLAGS, &$LDFLAGS_TAIL, &$CLANG_FLAGS, &$OBJCOPY_FLAGS, &$SIZE_FLAGS,
	                            &$OUTPUT, &$ARDUINO_CORES_DIR, &$ARDUINO_SKEL)
	{
		// External binaries.
		$CC = $compiler_config["cc"];
		$CPP = $compiler_config["cpp"];
		$AS = $compiler_config["as"];
		$LD = $compiler_config["ld"];
		$CLANG = $compiler_config["clang"];
		$OBJCOPY = $compiler_config["objcopy"];
		$SIZE = $compiler_config["size"];
		// Standard command-line arguments used by the binaries.
		$CFLAGS = $compiler_config["cflags"];
		$CPPFLAGS = $compiler_config["cppflags"];
		$ASFLAGS = $compiler_config["asflags"];
		$LDFLAGS = $compiler_config["ldflags"];
		$LDFLAGS_TAIL = $compiler_config["ldflags_tail"];
		$CLANG_FLAGS = $compiler_config["clang_flags"];
		$OBJCOPY_FLAGS = $compiler_config["objcopy_flags"];
		$SIZE_FLAGS = $compiler_config["size_flags"];
		// The default name of the output file.
		$OUTPUT = $compiler_config["output"];
		// Path to arduino-core-files repository.
		$ARDUINO_CORES_DIR = $compiler_config["arduino_cores_dir"];
		// The name of the Arduino skeleton file.
		$ARDUINO_SKEL = $compiler_config["arduino_skel"];
	}*/

	private function set_variables($request, &$format, &$libraries, &$version, &$mcu, &$f_cpu, &$core, &$variant, &$vid, &$pid)
	{
		// Extract the request options for easier access.
		$format = $request->format;
		$libraries = $request->libraries;
		$version = $request->version;
		$mcu = $request->build->mcu;
		$f_cpu = $request->build->f_cpu;
		$core = $request->build->core;
		$variant = $request->build->variant;

		// Set the appropriate variables for vid and pid (Leonardo).
		$vid = ($variant == "leonardo") ? $request->build->vid : "";
		$pid = ($variant == "leonardo") ? $request->build->pid : "";
	}
	
}
