<?php

/**
 * Contao Open Source CMS
 *
 * @copyright  MEN AT WORK 2014
 * @package    rootsearch
 * @license    GNU/LGPL
 * @filesource
 */

use Contao\CoreBundle\ContaoCoreBundle;

/**
 * Class ModuleSearch
 * Based on ModuleSearch from Contao 3.1.5
 */
class ModuleRootSearch extends \Module
{

	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'mod_search';

	/**
	 * Base url cache
	 * @var array
	 */
	protected $arrBaseUrl = array();


	/**
	 * Display a wildcard in the back end
	 * @return string
	 */
	public function generate()
	{
		if (TL_MODE == 'BE')
		{
			$objTemplate = new \BackendTemplate('be_wildcard');

			$objTemplate->wildcard = '### MULTI-ROOT WEBSITE SEARCH ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		$this->searchRoots = deserialize($this->searchRoots);
		if (!is_array($this->searchRoots) || !count($this->searchRoots))
		{
			return '';
		}

		return parent::generate();
	}


	/**
	 * Generate the module
	 */
	protected function compile()
	{
		$currentPage = $GLOBALS['objPage'];
		$currentPage = $currentPage->id;

		// Mark the x and y parameter as used (see #4277)
		if (isset($_GET['x']))
		{
			\Input::get('x');
			\Input::get('y');
		}

		// Trigger the search module from a custom form
		if (!isset($_GET['keywords']) && \Input::post('FORM_SUBMIT') == 'tl_search')
		{
			$_GET['keywords'] = \Input::post('keywords');
			$_GET['query_type'] = \Input::post('query_type');
			$_GET['per_page'] = \Input::post('per_page');
		}

		$blnFuzzy = $this->fuzzy;
		$strQueryType = \Input::get('query_type') ?: $this->queryType;

		// Remove insert tags
		$strKeywords = trim(\Input::get('keywords'));
		$strKeywords = preg_replace('/\{\{[^\}]*\}\}/', '', $strKeywords);

		if (\class_exists(ContaoCoreBundle::class))
		{
			$this->Template->uniqueId = $this->id;
			$this->Template->queryType = $strQueryType;
			$this->Template->keyword = \StringUtil::specialchars($strKeywords);
			$this->Template->keywordLabel = $GLOBALS['TL_LANG']['MSC']['keywords'];
			$this->Template->optionsLabel = $GLOBALS['TL_LANG']['MSC']['options'];
			$this->Template->search = \StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['searchLabel']);
			$this->Template->matchAll = \StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['matchAll']);
			$this->Template->matchAny = \StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['matchAny']);
			$this->Template->action = ampersand(\Environment::get('indexFreeRequest'));
			$this->Template->advanced = ($this->searchType == 'advanced');

			// Redirect page
			if ($this->jumpTo && ($objTarget = $this->objModel->getRelated('jumpTo')) instanceof PageModel)
			{
				/** @var PageModel $objTarget */
				$this->Template->action = $objTarget->getFrontendUrl();
			}
		}
		else
		{
			$objFormTemplate = new \FrontendTemplate((($this->searchType == 'advanced') ? 'mod_search_advanced' : 'mod_search_simple'));

			$objFormTemplate->uniqueId = $this->id;
			$objFormTemplate->queryType = $strQueryType;
			$objFormTemplate->keyword = specialchars($strKeywords);
			$objFormTemplate->keywordLabel = $GLOBALS['TL_LANG']['MSC']['keywords'];
			$objFormTemplate->optionsLabel = $GLOBALS['TL_LANG']['MSC']['options'];
			$objFormTemplate->search = specialchars($GLOBALS['TL_LANG']['MSC']['searchLabel']);
			$objFormTemplate->matchAll = specialchars($GLOBALS['TL_LANG']['MSC']['matchAll']);
			$objFormTemplate->matchAny = specialchars($GLOBALS['TL_LANG']['MSC']['matchAny']);
			$objFormTemplate->id = ($GLOBALS['TL_CONFIG']['disableAlias'] && \Input::get('id')) ? \Input::get('id') : false;
			$objFormTemplate->action = ampersand(\Environment::get('indexFreeRequest'));

			// Redirect page
			if ($this->jumpTo && ($objTarget = $this->objModel->getRelated('jumpTo')) !== null)
			{
				$objFormTemplate->action = $this->generateFrontendUrl($objTarget->row());
			}

			$this->Template->form = $objFormTemplate->parse();
		}

		$this->Template->pagination = '';
		$this->Template->results = '';

		// Execute the search if there are keywords
		if ($strKeywords != '' && $strKeywords != '*' && (!$this->jumpTo || $this->jumpTo == $currentPage))
		{
			// Get the pages
			$arrPages = $this->Database->getChildRecords($this->searchRoots, 'tl_page');

			// HOOK: add custom logic (see #5223)
			if (isset($GLOBALS['TL_HOOKS']['customizeSearch']) && \is_array($GLOBALS['TL_HOOKS']['customizeSearch']))
			{
				foreach ($GLOBALS['TL_HOOKS']['customizeSearch'] as $callback)
				{
					$this->import($callback[0]);
					$this->{$callback[0]}->{$callback[1]}($arrPages, $strKeywords, $strQueryType, $blnFuzzy, $this);
				}
			}

			// Return if there are no pages
			if (empty($arrPages) || !\is_array($arrPages))
			{
				$this->log('No searchable pages found', 'ModuleSearch compile()', TL_ERROR);
				return;
			}

			$arrResult = null;
			$strChecksum = md5($strKeywords . $strQueryType . $intRootId . $blnFuzzy);
			$query_starttime = microtime(true);
			$strCacheFile = 'system/cache/search/' . $strChecksum . '.json';

			// Load the cached result
			if (file_exists(TL_ROOT . '/' . $strCacheFile))
			{
				$objFile = new \File($strCacheFile, true);

				if ($objFile->mtime > time() - 1800)
				{
					$arrResult = json_decode($objFile->getContent(), true);
				}
				else
				{
					$objFile->delete();
				}
			}

			// Cache the result
			if ($arrResult === null)
			{
				try
				{
					$objSearch = \Search::searchFor($strKeywords, ($strQueryType == 'or'), $arrPages, 0, 0, $blnFuzzy);
					$arrResult = $objSearch->fetchAllAssoc();
				}
				catch (\Exception $e)
				{
					$this->log('Website search failed: ' . $e->getMessage(), __METHOD__, TL_ERROR);
					$arrResult = array();
				}

				\File::putContent($strCacheFile, json_encode($arrResult));
			}

			$query_endtime = microtime(true);

			// Sort out protected pages
			if (\Config::get('indexProtected') && !BE_USER_LOGGED_IN)
			{
				$this->import('FrontendUser', 'User');

				foreach ($arrResult as $k=>$v)
				{
					if ($v['protected'])
					{
						if (!FE_USER_LOGGED_IN)
						{
							unset($arrResult[$k]);
						}
						else
						{
							$groups = deserialize($v['groups']);

							if (empty($groups) || !\is_array($groups) || !\count(array_intersect($groups, $this->User->groups)))
							{
								unset($arrResult[$k]);
							}
						}
					}
				}

				$arrResult = array_values($arrResult);
			}

			$count = \count($arrResult);

			$this->Template->count = $count;
			$this->Template->keywords = $strKeywords;

			// No results
			if ($count < 1)
			{
				$this->Template->header = sprintf($GLOBALS['TL_LANG']['MSC']['sEmpty'], $strKeywords);
				$this->Template->duration = substr($query_endtime-$query_starttime, 0, 6) . ' ' . $GLOBALS['TL_LANG']['MSC']['seconds'];
				return;
			}

			$from = 1;
			$to = $count;

			// Pagination
			if ($this->perPage > 0)
			{
				$id = 'page_s' . $this->id;
				$page = \Input::get($id) ?: 1;
				$per_page = \Input::get('per_page') ?: $this->perPage;

				// Do not index or cache the page if the page number is outside the range
				if ($page < 1 || $page > max(ceil($count/$per_page), 1))
				{
					global $objPage;
					$objPage->noSearch = 1;
					$objPage->cache = 0;

					// Send a 404 header
					header('HTTP/1.1 404 Not Found');
					return;
				}

				$from = (($page - 1) * $per_page) + 1;
				$to = (($from + $per_page) > $count) ? $count : ($from + $per_page - 1);

				// Pagination menu
				if ($to < $count || $from > 1)
				{
					$objPagination = new \Pagination($count, $per_page, \Config::get('maxPaginationLinks'), $id);
					$this->Template->pagination = $objPagination->generate("\n  ");
				}
			}

			// Get the results
			for ($i=($from-1); $i<$to && $i<$count; $i++)
			{
				$objTemplate = new \FrontendTemplate($this->searchTpl ?: 'search_default');

				$strBase = stripos($arrResult[$i]['url'], 'http') !== 0 ? $this->getBaseUrl($arrResult[$i]['pid']) : '';
				$objTemplate->url = $strBase . $arrResult[$i]['url'];
				$objTemplate->link = $arrResult[$i]['title'];
				$objTemplate->href = $strBase . $arrResult[$i]['url'];
				$objTemplate->title = specialchars($arrResult[$i]['title']);
				$objTemplate->class = (($i == ($from - 1)) ? 'first ' : '') . (($i == ($to - 1) || $i == ($count - 1)) ? 'last ' : '') . (($i % 2 == 0) ? 'even' : 'odd');
				$objTemplate->relevance = sprintf($GLOBALS['TL_LANG']['MSC']['relevance'], number_format($arrResult[$i]['relevance'] / $arrResult[0]['relevance'] * 100, 2) . '%');
				$objTemplate->filesize = $arrResult[$i]['filesize'];
				$objTemplate->matches = $arrResult[$i]['matches'];

				$arrContext = array();
				$arrMatches = trimsplit(',', $arrResult[$i]['matches']);

				// Get the context
				foreach ($arrMatches as $strWord)
				{
					$arrChunks = array();
					preg_match_all('/(^|\b.{0,'.$this->contextLength.'}\PL)' . str_replace('+', '\\+', $strWord) . '(\PL.{0,'.$this->contextLength.'}\b|$)/ui', $arrResult[$i]['text'], $arrChunks);

					foreach ($arrChunks[0] as $strContext)
					{
						$arrContext[] = ' ' . $strContext . ' ';
					}
				}

				// Shorten the context and highlight all keywords
				if (!empty($arrContext))
				{
					$objTemplate->context = trim(\StringUtil::substrHtml(implode('…', $arrContext), $this->totalLength));
					$objTemplate->context = preg_replace('/(\PL)(' . implode('|', $arrMatches) . ')(\PL)/ui', '$1<mark class="highlight">$2</mark>$3', $objTemplate->context);

					$objTemplate->hasContext = true;
				}

				$this->Template->results .= $objTemplate->parse();
			}

			$this->Template->header = vsprintf($GLOBALS['TL_LANG']['MSC']['sResults'], array($from, $to, $count, $strKeywords));
			$this->Template->duration = substr($query_endtime-$query_starttime, 0, 6) . ' ' . $GLOBALS['TL_LANG']['MSC']['seconds'];
		}
	}

	/**
	*
	* @param type $objPage
	* @return array
	*/
	public function getBaseUrl($intId) {

		if (!strlen($intId) ||$intId < 1)
		{
			   return array();
		}

		//check for cached results
		if (isset($this->arrBaseUrl[$intId]))
		{
			return $this->arrBaseUrl[$intId];
		}

		$objPage = $this->Database->prepare("SELECT * FROM tl_page WHERE id=?")
									   ->limit(1)
									   ->execute($intId);

		if ($objPage->numRows < 1)
		{
			return array();
		}

		if ($objPage->type != 'root')
		{
			$strUrl= $this->getBaseUrl($objPage->pid);
		}
		else
		{
			if ($objPage->dns != '')
			{
				if ($this->Environment->ssl)
				{
					$strProtocol = 'https';
				}
				else {
					$strProtocol = 'http';
				}

				$strUrl = $strProtocol . '://' . $objPage->dns . TL_PATH . '/';
			}
			else
			{
				$strUrl = '';
			}
		}
		return $this->arrBaseUrl[$objPage->id] = $strUrl;
	}


}
