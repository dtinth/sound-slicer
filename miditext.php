<?php

# MidiText by the DtTvB
# Version 2

function debug_msg($text) {
	echo "$text\n";
}

class Event {

	var $type, $time;

	function __construct($type, $time, $data = array()) {
		foreach ($data as $k => $v) {
			$this->$k = $v;
		}
		$this->type = $type;
		$this->time = $time;
	}

}

class NotesFile {

	var $mid;
	var $divisions;
	var $events;
	var $tempo = null, $bpm = null;

	function __construct($mid) {
		$this->mid = $mid;
	}
	
	function read() {
	
		// text file
		$mid = $this->mid;
		debug_msg ('==================================');
		debug_msg ('[ii] MIDI File: ' . $mid);
		
		// check text file
		if (strtolower(substr($mid, -4)) !== '.mid') {
			debug_msg ('[!!] Accept only .mid file!');
			return false;
		}
		if (!file_exists($mid)) {
			debug_msg ('[!!] .mid file not found!');
			return false;
		}
		return $this->readMIDI();

	}
	
	function readMidi() {
		$command = implode(' ', array_map('escapeshellarg', array(getenv('MIDICSV'), $this->mid)));
		$fp = popen($command, 'r');
		if (!$fp) {
			return false;
		}
		$this->divisions = 192;
		$this->events    = array();
		$this->notePool  = array();
		while (!feof($fp)) {
			$data = fgetcsv($fp);
			if (count($data) >= 3) {
				$this->readMidiLine($data);
			}
		}
		fclose($fp);
		usort($this->events, array($this, 'eventSort'));
		return count($this->events) > 0;
	}
	
	function eventSort($a, $b) {
		return $a->time - $b->time;
	}
	
	function addEvent($event) {
		$this->events[] = $event;
	}
	
	function readMidiLine($data) {
		$track = intval(array_shift($data));
		$time  = intval(array_shift($data));
		$type  = array_shift($data);
		if ($type == 'Header') {
			$this->divisions = intval($data[2]) * 4;
			$this->addEvent(new Event('divisions', $time, array('divisions' => $this->divisions)));
		} else if ($type == 'Tempo') {
			$tempo = $data[0];
			$bpm = round(1000000 / intval($tempo) * 60, 2);
			if ($this->tempo === null) {
				$this->tempo = $tempo;
			}
			if ($this->bpm === null) {
				$this->bpm = $bpm;
			}
			$this->addEvent(new Event('tempo', $time, array('bpm' => $bpm)));
		} else if ($type == 'Note_off_c' || $type == 'Note_on_c') {
			$channel  = intval(array_shift($data));
			$note     = intval(array_shift($data));
			$velocity = intval(array_shift($data));
			if ($type == 'Note_off_c') {
				$velocity = 0;
			}
			$trid = "$channel:$note";
			if (isset($this->notePool[$trid])) {
				$this->notePool[$trid]->length = $time - $this->notePool[$trid]->time;
				if ($this->notePool[$trid]->length > 0) {
					$this->addEvent($this->notePool[$trid]);
				}
				unset($this->notePool[$trid]);
			}
			if ($velocity > 0) {
				$this->notePool[$trid] = new Event('note', $time, array(
					'track'    => $track,
					'channel'  => $channel,
					'note'     => $note,
					'velocity' => $velocity,
				));
			}
		}
	}

	function process() {
		if (!$this->read()) {
			return false;
		}
		return true;
	}
	
}

class BMSNote {

	function __construct($time, $channel, $data) {
		$this->time = $time;
		$this->channel = $channel;
		$this->data = $data;
	}
	
	function line() {
		return sprintf("%03d0%07d%d\r\n", $this->channel, $this->time, $this->data);
	}

}

class BMSNoteData {

	var $data = array();
	
	function getId($time, $channel) {
		return "$time:$channel";
	}
	
	function getNote($time, $channel) {
		$id = $this->getId($time, $channel);
		if (!isset($this->data[$id])) {
			return null;
		}
		return $this->data[$id];
	}
	
	function setNote($time, $channel, $data) {
		$id = $this->getId($time, $channel);
		$this->data[$id] = new BMSNote($time, $channel, $data);
	}

	function saveFile($clipfile) {
		$fp = fopen($clipfile, "w");
		if (!$fp) {
			debug_msg ('[!!] Cannot create clipboard file: ' . $clipfile);
			return false;
		}
		fwrite($fp, 'BMSE ClipBoard Object Data Format' . "\r\n");
		foreach ($this->data as $note) {
			fwrite($fp, $note->line());
		}
		fclose($fp);
		return true;
	}

}



class MelodicSound {

	function __construct($channel, $note, $velocity, $length) {
		$this->channel = $channel;
		$this->note = $note;
		$this->velocity = $velocity;
		$this->length = $length;
		$this->id = 0;
	}

}

class TextFileWriter {

	function __construct($fp, $bpm, $divisions) {
		$this->fp = $fp;
		$this->time = 0;
		fprintf($this->fp, "%dbpm\n", $bpm);
		fprintf($this->fp, "%dth\n", $divisions);
	}
	
	function write($type, $time) {
		while ($this->time < $time) {
			fwrite($this->fp, '.');
			$this->time ++;
		}
		fwrite($this->fp, "\n$type");
		$this->time++;
	}

}

class MelodicFileProcessor {

	function __construct($main, $num, $file) {
		$this->main = $main;
		$this->num = $num;
		$this->file = $file;
		$this->noteTypes = array();
	}
	
	function getBMSTime($time) {
		return round(192 * $time / $this->file->divisions);
	}
	
	function process() {
		$this->setSounds();
		$this->reorderSounds();
		$this->generateBMSClipboard();
		$this->generateNotes();
	}
	
	function setSounds() {
		foreach ($this->file->events as $event) {
			if ($event->type == 'note') {
				$event->sound = $this->getSound($event);
			}
		}
	}
	
	function reorderSounds() {
		uksort($this->noteTypes, 'strnatcasecmp');
		$nextID = 1;
		foreach ($this->noteTypes as $sound) {
			$sound->id = $nextID++;
		}
	}
	
	function getSound($note) {
		$id = $note->channel . ':' . $note->note . ':' . $note->velocity . ':' . $note->length;
		if (!isset($this->noteTypes[$id])) {
			$this->noteTypes[$id] = new MelodicSound($note->channel, $note->note, $note->velocity, $note->length);
		}
		return $this->noteTypes[$id];
	}
	
	function generateBMSClipboard() {
		$bms = new BMSNoteData;
		$startChannel = 101;
		foreach ($this->file->events as $event) {
			if ($event->type == 'note') {
				$time = $this->getBMSTime($event->time);
				for ($channel = $startChannel; $channel <= 132; $channel ++) {
					if ($bms->getNote($time, $channel))
						continue;
					$bms->setNote($time, $channel, $event->sound->id);
					break;
				}
			}
		}
		$bms->saveFile(substr($this->file->mid, 0, -3) . 'bms-clipboard.txt');
	}

	function generateNotes() {
	
		$midfile = substr($this->file->mid, 0, -4) . '-notes.mid';
		$txtfile = substr($this->file->mid, 0, -4) . '-notes.txt';
		
		$command = implode(' ', array_map('escapeshellarg', array(getenv('CSVMIDI'), '-', $midfile)));
		$text = fopen($txtfile, 'w');
		$midi = popen($command, 'w');
		
		if (!$text || !$midi) {
			return false;
		}
		
		$writer = new TextFileWriter($text, $this->file->bpm, $this->file->divisions);
		fprintf($midi, "0, 0, Header, 1, 2, %d\n", $this->file->divisions / 4);
		fprintf($midi, "1, 0, Start_track\n");
		fprintf($midi, "1, 0, Tempo, %d\n", $this->file->tempo);
		fprintf($midi, "1, 0, Time_signature, 4, 2, 24, 8\n");
		fprintf($midi, "1, 0, End_track\n");
		fprintf($midi, "2, 0, Start_track\n");
		fprintf($midi, "2, 0, Title_t, \"miditext\"\n");
		$time = 0;
		foreach ($this->noteTypes as $sound) {
			$writer->write(',', $time);
			fprintf($midi, "2, %d, Note_on_c, %d, %d, %d\n", $time, $sound->channel, $sound->note, $sound->velocity);
			$time += $sound->length;
			fprintf($midi, "2, %d, Note_off_c, %d, %d, %d\n", $time, $sound->channel, $sound->note, 64);
			$time += $this->main->gap * $this->file->divisions;
		}
		$writer->write('.', $time);
		fprintf($midi, "2, %d, End_track\n", $time);
		fprintf($midi, "0, 0, End_of_file\n", $time);
		
		fclose($midi);
		fclose($text);
		
	}

}

class MelodicProcessor {

	var $gap = 0.4375;

	function __construct($options = array()) {
		foreach ($options as $k => $v) {
			$this->$k = $v;
		}
	}

	function process($files) {
		foreach ($files as $num => $file) {
			$this->processFile($num, $file);
		}
	}
	
	function processFile($num, $file) {
		$processor = new MelodicFileProcessor($this, $num, $file);
		$processor->process();
	}

}

class RhythmicFileProcessor {

	function __construct($main, $num, $file) {
		$this->main = $main;
		$this->num = $num;
		$this->file = $file;
	}
	
	function process() {
		$this->generateNotes();
	}
	
	function generateNotes() {
	
		$txtfile = substr($this->file->mid, 0, -3) . 'txt';
		$fp = fopen($txtfile, 'w');
		if (!$fp) {
			return false;
		}
		
		$writer = new TextFileWriter($fp, $this->file->bpm, $this->file->divisions);
		$maxTime = 0;
		foreach ($this->file->events as $event) {
			if ($event->type == 'note') {
				$writer->write(',', $event->time);
				$maxTime = max($maxTime, $event->time);
			}
		}
		$writer->write('.', $maxTime + $this->file->divisions);
		fclose($fp);
		
	}


}

class RhythmicProcessor {

	function process($files) {
		foreach ($files as $num => $file) {
			$this->processFile($num, $file);
		}
	}
	
	function processFile($num, $file) {
		$processor = new RhythmicFileProcessor($this, $num, $file);
		$processor->process();
	}

}



function process_file($txt) {

	// process it!
	$notes = new NotesFile($txt);
	if (!$notes->process()) {
		return false;
	}
	
	return $notes;
	
}

$args = $argv;
array_shift($args);
$fnname = array_shift($args);

debug_msg ('==================================');
debug_msg ('[..] Reading MIDI files.');
$files = array();
foreach ($args as $v) {
	$file = process_file($v);
	if ($file) {
		$files[] = $file;
	}
}

function process_files_rhythmic(&$files) {
	$processor = new RhythmicProcessor();
	$processor->process($files);
}

function get_gap() {
	echo  "Please input gap between each note, in fraction.\n"
		. "Examples:  0.125\n"
		. "           5 / 16\n\n"
		. "Gap: ";
	$data = trim(fgets(STDIN));
	return eval('return (' . $data . ');');
}

function process_files_melodic(&$files) {
	$processor = new MelodicProcessor(array('gap' => get_gap()));
	$processor->process($files);
}

$fnname($files);