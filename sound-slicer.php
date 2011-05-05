<?php

# Sound Slicer by the DtTvB
# Version 2

function debug_msg($text) {
	echo "$text\n";
}

class Slice {

	var $wav, $time, $position, $outname;
	var $prev  = null;
	var $next  = null;
	
	function __construct($wav, $time, $position) {
		$this->wav      = $wav;
		$this->time     = $time;
		$this->length   = 0;
		$this->position = $position;
	}
	
	function update($time) {
		$this->length = $time - $this->time;
	}
	
}

class SoXCommand {

	var $infile, $outfile;
	var $start,  $length;
	var $fadeIn, $fadeOut;

	function generateShellCommand() {
		$parts = array(getenv('SOX'));
		$parts[] = $this->infile;
		$parts[] = $this->outfile;
		$parts[] = 'trim';
		$parts[] = sprintf("%ds", $this->start);
		$parts[] = sprintf("%ds", $this->length);
		$parts[] = 'fade';
		$parts[] = sprintf("%ds", $this->fadeIn);
		$parts[] = sprintf("%ds", $this->length);
		$parts[] = sprintf("%ds", $this->fadeOut);
		return implode(' ', array_map('escapeshellarg', $parts));
	}
	
	function execute($current, $total) {
		printf("[ %d / %d ] %s => %s (trim %ds %ds, fade %ds %ds)\n",
			$current, $total,
			basename($this->infile), basename($this->outfile),
			$this->start, $this->length, $this->fadeIn, $this->fadeOut
		);
		shell_exec($this->generateShellCommand());
	}

}

class CommandCreator {

	var $slice, $command;
	var $in = 10, $out = 30;

	function __construct($slice) {
		$this->slice = $slice;
	}

	function createCommand() {
		$this->command = new SoXCommand;
		$this->command->infile  = $this->slice->wav;
		$this->command->outfile = $this->slice->outname;
		$this->setTiming();
		return $this->command;
	}
	
	function samples($x) { return round(44100 * $x); }
	
	function getFadeIn()  { return $this->in; }
	function getFadeOut() { return $this->out; }
	
	function getSampleStart()  { return $this->samples($this->slice->time); }
	function getSampleLength() { return $this->samples($this->slice->length); }
	
	function setTiming() {
		$this->command->start   = $this->getSampleStart();
		$this->command->length  = $this->getSampleLength();
		$this->command->fadeIn  = $this->getFadeIn();
		$this->command->fadeOut = $this->getFadeOut();
	}

}

class Slicer {

	var $txt, $wav, $mode, $bpm, $divisions;
	var $time, $position;
	
	var $outdir;
	
	function __construct($txt) {
		$this->txt = $txt;
	}
	
	function advance() {
		$this->position += 1 / $this->divisions;
		$this->time     += 1 / $this->divisions * 4 * 60 / $this->bpm;
	}
	
	function read() {
	
		// text file
		$txt = $this->txt;
		debug_msg ('==================================');
		debug_msg ('[ii] Text File: ' . $txt);
		
		// check text file
		if (strtolower(substr($txt, -4)) !== '.txt') {
			debug_msg ('[!!] Accept only .txt file!');
			return false;
		}
		if (!file_exists($txt)) {
			debug_msg ('[!!] .txt file not found!');
			return false;
		}
		
		// wave file
		$this->wav = substr($txt, 0, -3) . 'wav';
		debug_msg ('[ii] Wave File: ' . $this->wav);
		
		// check wave file
		if (!file_exists($this->wav)) {
			debug_msg ('[!!] .wav file not found!');
			return false;
		}
		
		// set output directory
		$this->outdir = dirname($this->wav) . DIRECTORY_SEPARATOR . 'wav';
		
		// read text file
		debug_msg ('[..] Opening Text File: ' . $txt);
		$this->data = file_get_contents($txt);
		return true;

	}
	
	function generateFilename($index) {
		$basename = sprintf("%s-%03s.wav", substr(basename($this->wav), 0, -4), base_convert($index + 1, 10, 10));
		return sprintf("%s%s%s", $this->outdir, DIRECTORY_SEPARATOR, $basename);
	}
	
	function process() {
	
		if (!$this->read()) {
			return false;
		}
	
		$this->mode      = 'normal';
		$this->bpm       = 140;
		$this->divisions = 4;
		$this->time      = 0;
		$this->position  = 0;
		$this->slices    = array();
		
		if (preg_match('~#(soft|double)~', $this->data, $match)) {
			$this->mode = $match[1];
		}
		if (!preg_match_all('~(?:[\d.]+)bpm|(?:[\d.]+)(?:st|nd|rd|th)|\.|,|\'~i', $this->data, $tokens)) {
			debug_msg ('[!!] No tokens found.');
			return false;
		}
		
		$tokens = array_map('strtolower', $tokens[0]);
		
		debug_msg ('[ii] Render Mode: ' . $this->mode);
		debug_msg ('[ii] ' . count($tokens) . ' tokens found.');
		
		$slice = null;
		foreach ($tokens as $v) {
			if ($v == ',') {
				$slice = new Slice($this->wav, $this->time, $this->position);
				$this->slices[] = $slice;
			} else if ($v == '\'') {
				$slice = null;
			}
			if ($v == ',' || $v == '.' || $v == '\'') {
				$this->advance();
				if ($slice) {
					$slice->update($this->time);
				}
			} else if (substr($v, -3) == 'bpm') {
				$this->bpm = floatval(substr($v, 0, -3));
			} else {
				$this->divisions = floatval(substr($v, 0, -2));
			}
		}
		
		$this->connect();
		
		return count($this->slices) > 0;
		
	}
	
	function connect() {
		$count = count($this->slices);
		for ($i = 0; $i < $count; $i ++) {
			$this->slices[$i]->outname = $this->generateFilename($i);
		}
		for ($i = 1; $i < $count - 1; $i ++) {
			$this->slices[$i - 1]->next = $this->slices[$i];
			$this->slices[$i]->next     = $this->slices[$i + 1];
			$this->slices[$i]->prev     = $this->slices[$i - 1];
			$this->slices[$i + 1]->prev = $this->slices[$i];
		}
	}
	
	function generateCommands(&$commands) {
		foreach ($this->slices as $slice) {
			$creator = new CommandCreator($slice);
			$commands[] = $creator->createCommand();
		}
	}
	
	function createClipboardFile($current, $total) {
		$clipfile = substr($this->txt, 0, -3) . 'bms-clipboard.txt';
		printf("[ %d / %d ] %s => %s\n",
			$current, $total,
			basename($this->txt), basename($clipfile)
		);
		$fp = fopen($clipfile, "w");
		if (!$fp) {
			debug_msg ('[!!] Cannot create clipboard file: ' . $clipfile);
			return false;
		}
		fwrite($fp, 'BMSE ClipBoard Object Data Format' . "\r\n");
		$nextNumber = 1;
		foreach ($this->slices as $slice) {
			fwrite($fp, sprintf('1010%07d%d', round($slice->position * 192), $nextNumber++) . "\r\n");
		}
		fclose($fp);
		return true;
	}
	
}

function process_file($txt) {

	// process it!
	$slicer = new Slicer($txt);
	if (!$slicer->process()) {
		return false;
	}
	
	return $slicer;
	
}

$args = $argv;
array_shift($args);

$commands    = array();
$files       = array();
$directories = array();
foreach ($args as $v) {
	$file = process_file($v);
	if ($file) {
		$file->generateCommands($commands);
		$files[] = $file;
		$directories[] = $file->outdir;
	}
}

debug_msg ('==================================');
debug_msg ('[..] Creating BMS clipboard files.');
foreach ($files as $num => $file) {
	$file->createClipboardFile($num, count($files));
}

debug_msg ('==================================');
debug_msg ('[..] Creating directories.');

$made = 0;
foreach (array_unique($directories) as $directory) {
	if (!file_exists($directory)) {
		debug_msg ('[..] Making output directory: ' . $directory);
		mkdir ($directory, true);
		$made ++;
	} else {
		debug_msg ('[ii] Output directory already exists: ' . $directory);
	}
}

debug_msg ('[ii] Number of new directories created: ' . $made . '');

debug_msg ('==================================');

$count = count($commands);
debug_msg ('[ii] Number of files to be generated: ' . $count);
debug_msg ('[ii] Generating wav files..');

foreach ($commands as $num => $command) {
	$command->execute($num + 1, $count);
}
