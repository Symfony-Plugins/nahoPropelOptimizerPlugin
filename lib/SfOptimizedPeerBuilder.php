<?php

include_once 'addon/propel/builder/SfPeerBuilder.php';

class SfOptimizedPeerBuilder extends SfPeerBuilder
{
  
  public function build()
  {
    // Get original built code
    $peerCode = parent::build();
    
    // Remove useless includes
    if (!DataModelBuilder::getBuildProperty('builderAddIncludes')) {
      //remove all inline includes:
      //peer class include inline the mapbuilder classes
      $peerCode = preg_replace("/(include|require)_once\s*.*\.php.*\s*/", "", $peerCode);
    }
    
    // Change implicit joins (all inner) to explicit INNER or LEFT, depending on the fact the key can be null or not
    if (!DataModelBuilder::getBuildProperty('builderImplicitJoins')) {
      foreach ($this->getTable()->getColumns() as $column) {
        if ($column->isForeignKey()) {
          $colName = PeerBuilder::getColumnName($column, $this->getTable()->getPhpName());
          $from = '/->addJoin\(' . preg_quote($colName, '/') . '\s*,\s*([^,]*?)\)/';
          if ($column->isNotNull()) {
            $to = '->addJoin(' . $colName . ', $1, Criteria::INNER_JOIN)';
          } else {
            $to = '->addJoin(' . $colName . ', $1, Criteria::LEFT_JOIN)';
          }
          $peerCode = preg_replace($from, $to, $peerCode);
        }
      }
    }
    
    // remove calls to Propel::import(), which prevent to extend plugin's model classes
    if (!DataModelBuilder::getBuildProperty('builderAddPropelImports')) {
      $from = '/Propel::import\((.*?)\)/';
      $to = 'substr($1, ($pos=strrpos($1,\'.\'))?$pos+1:0)';
      $peerCode = preg_replace($from, $to, $peerCode);
    }
    
    return $peerCode;
   }

}
