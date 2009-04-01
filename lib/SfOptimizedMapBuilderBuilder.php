<?php

require_once sfConfig::get('sf_symfony_lib_dir').'/addon/propel/builder/SfMapBuilderBuilder.php';

class SfOptimizedMapBuilderBuilder extends SfMapBuilderBuilder
{
  
  public function build()
  {
    $string = parent::build();
    
    if (!DataModelBuilder::getBuildProperty('builderBigintAsString')) {
      $string = str_replace("'string', CreoleTypes::BIGINT", "'int', CreoleTypes::BIGINT", $string);
    }
    
    return $string;
  }
  
}
