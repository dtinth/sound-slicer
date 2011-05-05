sound-slicer
============

`sound-slicer` is the app that helps me slice the sound files to make BMS notecharts.

It reads in the `.txt` files and slices the corresponding `.wav` file into parts.
It also generates a BMS clipboard file which can be transposed and pasted into
BMx Sequence Editor.


Project Layout
--------------

When creating BMS using this tool, you are supposed to put the original project
files (e.g. original samples, .txt files) in any folder, and put the sliced wav
files along with BMS files you are working in a subfolder of it, called `wav`.

For example, it might look like this:

* Synth.wav
* Synth.txt
* wav
    * song.bms
    * Synth-001.wav
    * Synth-002.wav
    * Synth-003.wav
    * Synth-004.wav
    * Synth-005.wav
    * ...


miditext
--------

Also included here is miditext, another app that reads in a MIDI file and
generates a .txt file. There are 2 styles.

* Rhythmic: slice every events found in a MIDI file. The length of the resulting
  file is equal to the length of the MIDI file. It generates a .txt file.
  
  This is the simplest way of converting, but may result in slight gaps between
  sliced samples. This method does not detect like sounds and discard them. So
  if your MIDI file has 1000 notes, it will generate 1000 slices.
  
* Melodic: it categorizes each note found in a MIDI file by note, length and
  velocity. It then generates another .mid file with these notes organized along
  with a .txt file to slice them. It also generates a clipboard file for you
  to put the original notes into BMS. You then have to import the new .mid file
  back into your audio application and render it as .wav file, and slice with
  the generated .txt file and then use the generated clipboard file to put
  notes in your BMS file.
  
  This method is recommended if you have a lot of notes in common. For example,
  if your bassline has 500 notes, but has only 10 different notes, and they
  all use the same instrument. You can export the entire bassline and convert
  using melodic style instead. This results in only 10 slices being reused
  throughout the whole song, instead of 500, each being used one.

Both styles does not support BPM changes.