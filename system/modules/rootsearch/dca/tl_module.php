<?php

/**
 * Contao Open Source CMS
 *
 * @copyright  MEN AT WORK 2014 
 * @package    rootsearch
 * @license    GNU/LGPL 
 * @filesource
 */

/**
 * Palettes
 */
$GLOBALS['TL_DCA']['tl_module']['palettes']['rootsearch'] = '{title_legend},name,headline,type;{config_legend},queryType,fuzzy,contextLength,totalLength,perPage,searchType;{redirect_legend:hide},jumpTo;{reference_legend:hide},searchRoots;{template_legend:hide},searchTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID,space';

/**
 * Fields
 */
$GLOBALS['TL_DCA']['tl_module']['fields']['searchRoots'] = array
(
	'label'             => &$GLOBALS['TL_LANG']['tl_module']['searchRoots'],
	'inputType'         => 'checkbox',
	'options_callback'  => array('tl_module_rootsearch', 'getRootPages'),
	'eval'              => array('multiple' => true, 'tl_class' => 'clr'),
	'sql'               => "blob NULL"
);


class tl_module_rootsearch extends Backend
{
	
	public function getRootPages($dc)
	{
		$arrPages = array();
		
		$objPages = $this->Database->execute("SELECT * FROM tl_page WHERE type='root'");
		
		while( $objPages->next() )
		{
			$arrPages[$objPages->id] = $objPages->title . (strlen($objPages->dns) ? (' (' . $objPages->dns . ')') : '');
		}
		
		return $arrPages;
	}
}