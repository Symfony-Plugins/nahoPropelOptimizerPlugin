<?php

// Intermediate classes dynamically generated to allow usage of sfAlternativeSchemaPlugin
// @TODO Allow any other classes, and any number of classes (possible ?)
// @TODO Do not use eval

// Object
if (class_exists('SfAlternativeObjectBuilder')) {
  eval('class NahoObjectBuilder extends SfAlternativeObjectBuilder { }');
} else {
  eval('class NahoObjectBuilder extends SfObjectBuilder { }');
}

// Peer
if (class_exists('SfAlternativePeerBuilder')) {
  eval('class NahoPeerBuilder extends SfAlternativePeerBuilder { }');
} else {
  eval('class NahoPeerBuilder extends SfPeerBuilder { }');
}
