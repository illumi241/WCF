<?php
namespace wcf\system\package\plugin;
use wcf\data\box\Box;
use wcf\data\box\BoxEditor;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\exception\SystemException;
use wcf\system\WCF;

/**
 * Installs, updates and deletes boxes.
 * 
 * @author	Alexander Ebert
 * @copyright	2001-2015 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf
 * @subpackage	acp.package.plugin
 * @category	Community Framework
 */
class BoxPackageInstallationPlugin extends AbstractXMLPackageInstallationPlugin {
	/**
	 * @inheritDoc
	 */
	public $className = BoxEditor::class;
	
	/**
	 * @inheritDoc
	 */
	public $tagName = 'box';
	
	/**
	 * visibility exceptions per box
	 * @var	string[]
	 */
	public $visibilityExceptions = [];
	
	/**
	 * @inheritDoc
	 */
	protected function handleDelete(array $items) {
		$sql = "DELETE FROM	wcf".WCF_N."_box
			WHERE		identifier = ?
					AND packageID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		
		WCF::getDB()->beginTransaction();
		foreach ($items as $item) {
			$statement->execute([
				$item['attributes']['identifier'],
				$this->installation->getPackageID()
			]);
		}
		WCF::getDB()->commitTransaction();
	}
	
	/**
	 * @inheritDoc
	 * @throws	SystemException
	 */
	protected function getElement(\DOMXPath $xpath, array &$elements, \DOMElement $element) {
		$nodeValue = $element->nodeValue;
		
		if ($element->tagName === 'name' || $element->tagName === 'title') {
			if (empty($element->getAttribute('language'))) {
				throw new SystemException("Missing required attribute 'language' for '" . $element->tagName . "' element (box '" . $element->parentNode->getAttribute('identifier') . "')");
			}
			
			// element can occur multiple times using the `language` attribute
			if (!isset($elements[$element->tagName])) $elements[$element->tagName] = [];
			
			$elements[$element->tagName][$element->getAttribute('language')] = $element->nodeValue;
		}
		else if ($element->tagName === 'content') {
			// content can occur multiple times using the `language` attribute
			if (!isset($elements['content'])) $elements['content'] = [];
			
			$children = [];
			/** @var \DOMElement $child */
			foreach ($xpath->query('child::*', $element) as $child) {
				$children[$child->tagName] = $child->nodeValue;
			}
			
			if (empty($children['title'])) {
				throw new SystemException("Expected non-empty child element 'title' for 'content' element (box '" . $element->parentNode->getAttribute('identifier') . "')");
			}
			if (empty($children['content'])) {
				throw new SystemException("Expected non-empty child element 'content' for 'content' element (box '" . $element->parentNode->getAttribute('identifier') . "')");
			}
			
			$elements['content'][$element->getAttribute('language')] = [
				'content' => $children['content'],
				'title' => $children['title']
			];
		}
		else if ($element->tagName === 'visibilityExceptions') {
			$elements['visibilityExceptions'] = [];
			/** @var \DOMElement $child */
			foreach ($xpath->query('child::*', $element) as $child) {
				$elements['visibilityExceptions'][] = $child->nodeValue;
			}
		}
		else {
			$elements[$element->tagName] = $nodeValue;
		}
	}
	
	/**
	 * @inheritDoc
	 * @throws	SystemException
	 */
	protected function prepareImport(array $data) {
		$boxType = $data['elements']['boxType'];
		$controller = '';
		$identifier = $data['attributes']['identifier'];
		$isMultilingual = false;
		$position = $data['elements']['position'];
		
		if (!in_array($position, ['bottom', 'contentBottom', 'contentTop', 'footer', 'footerBoxes', 'headerBoxes', 'hero', 'sidebarLeft', 'sidebarRight', 'top'])) {
			throw new SystemException("Unknown box position '{$position}' for box '{$identifier}'");
		}
		
		switch ($boxType) {
			case 'system':
				if (empty($data['elements']['controller'])) {
					throw new SystemException("Missing required element 'controller' for 'system'-type box '{$identifier}'");
				}
				
				$controller = $data['elements']['controller'];
				break;
			
			case 'html':
			case 'text':
			case 'tpl':
				if (empty($data['elements']['content'])) {
					throw new SystemException("Missing required 'content' element(s) for box '{$identifier}'");
				}
				
				if (count($data['elements']['content']) === 1) {
					if (!isset($data['elements']['content'][''])) {
						throw new SystemException("Expected one 'content' element without a 'language' attribute for box '{$identifier}'");
					}
				}
				else {
					$isMultilingual = true;
					
					if (isset($data['elements']['content'][''])) {
						throw new SystemException("Cannot mix 'content' elements with and without 'language' attribute for box '{$identifier}'");
					}
				}
				
				break;
			
			default:
				throw new SystemException("Unknown type '{$boxType}' for box '{$identifier}");
				break;
		}
		
		if (!empty($data['elements']['visibilityExceptions'])) {
			$this->visibilityExceptions[$identifier] = $data['elements']['visibilityExceptions'];
		}
		
		return [
			'identifier' => $identifier,
			'name' => $this->getI18nValues($data['elements']['name'], true),
			'boxType' => $boxType,
			'position' => $position,
			'showOrder' => $this->getItemOrder($position),
			'visibleEverywhere' => (!empty($data['elements']['visibleEverywhere'])) ? 1 : 0,
			'isMultilingual' => ($isMultilingual) ? '1' : '0',
			'cssClassName' => (!empty($data['elements']['cssClassName'])) ? $data['elements']['cssClassName'] : '',
			'showHeader' => (!empty($data['elements']['showHeader'])) ? 1 : 0,
			'originIsSystem' => 1,
			'controller' => $controller
		];
	}
	
	/**
	 * @inheritDoc
	 */
	protected function findExistingItem(array $data) {
		$sql = "SELECT	*
			FROM	wcf".WCF_N."_box
			WHERE	identifier = ?
				AND packageID = ?";
		$parameters = array(
			$data['identifier'],
			$this->installation->getPackageID()
		);
		
		return array(
			'sql' => $sql,
			'parameters' => $parameters
		);
	}
	
	/**
	 * Returns the show order for a new item that will append it to the current
	 * menu or parent item.
	 *
	 * @param	string		$position	box position
	 * @return	integer
	 */
	protected function getItemOrder($position) {
		$sql = "SELECT  MAX(showOrder) AS showOrder
			FROM	wcf".WCF_N."_box
			WHERE   position = ?";
		$statement = WCF::getDB()->prepareStatement($sql, 1);
		$statement->execute([$position]);
		
		$row = $statement->fetchSingleRow();
		
		return (!$row['showOrder']) ? 1 : $row['showOrder'] + 1;
	}
	
	/**
	 * @inheritDoc
	 */
	protected function import(array $row, array $data) {
		// updating boxes is only supported for 'system' type boxes, all other
		// types would potentially overwrite changes made by the user if updated
		if (!empty($row) && $row['boxType'] !== 'system') {
			return new Box(null, $row);
		}
		
		return parent::import($row, $data);
	}
	
	/**
	 * @inheritDoc
	 */
	protected function postImport() {
		if (empty($this->visibilityExceptions)) return;
		
		// get all boxes belonging to the identifiers
		$conditions = new PreparedStatementConditionBuilder();
		$conditions->add("identifier IN (?)", [array_keys($this->visibilityExceptions)]);
		$conditions->add("packageID = ?", [$this->installation->getPackageID()]);
		
		$sql = "SELECT	*
			FROM	wcf".WCF_N."_box
			".$conditions;
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute($conditions->getParameters());
		$boxes = [];
		while ($box = $statement->fetchObject(Box::class)) {
			$boxes[$box->identifier] = $box;
		}
		
		// save visibility exceptions
		$sql = "DELETE FROM     wcf".WCF_N."_box_to_page
			WHERE           boxID = ?";
		$deleteStatement = WCF::getDB()->prepareStatement($sql);		
		$sql = "INSERT IGNORE   wcf".WCF_N."_box_to_page
					(boxID, pageID, visible)
			VALUES          (?, ?, ?)";
		$insertStatement = WCF::getDB()->prepareStatement($sql);
		foreach ($this->visibilityExceptions as $boxIdentifier => $pages) {
			// delete old visibility exceptions
			$deleteStatement->execute([$boxes[$boxIdentifier]->boxID]);
			
			// get page ids
			$conditionBuilder = new PreparedStatementConditionBuilder();
			$conditionBuilder->add('identifier IN (?)', array($pages));
			$sql = "SELECT  pageID
				FROM    wcf".WCF_N."_page
				".$conditionBuilder;
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			$pageIDs = [];
			while ($row = $statement->fetchArray()) {
				$pageIDs[] = $row['pageID'];
			}
			
			// save page ids
			foreach ($pageIDs as $pageID) {
				$insertStatement->execute([$boxes[$boxIdentifier]->boxID, $pageID, ($boxes[$boxIdentifier]->visibleEverywhere ? 0 : 1)]);
			}
		}
	}
}
