<?php
/**
 * @package    synhikashopwithmenu
 * @author     Dmitry Tsymbal <cymbal@delo-design.ru>
 * @copyright  Copyright © 2019 Delo Design. All rights reserved.
 * @license    GNU General Public License version 3 or later; see license.txt
 * @link       https://delo-design.ru
 */

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Menu\MenuItem;
use Joomla\CMS\Menu\SiteMenu;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseDriver;

defined('_JEXEC') or die;

/**
 * plgHikashopSynhikashopwithmenu plugin.
 *
 * @package   synhikashopwithmenu
 * @since     1.0.0
 */
class plgHikashopSynhikashopwithmenu extends CMSPlugin
{

	/**
	 * @var array
	 */
	protected $messages = [];

	/**
	 * Application object
	 *
	 * @var    CMSApplication
	 * @since  1.0.0
	 */
	protected $app;


	/**
	 * Database object
	 *
	 * @var    DatabaseDriver
	 * @since  1.0.0
	 */
	protected $db;


	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var    boolean
	 * @since  1.0.0
	 */
	protected $autoloadLanguage = true;


	/**
	 * @param $subject
	 * @param $config
	 */
	public function plgHikashopSynhikashopwithmenu(&$subject, $config)
	{
		parent::__construct($subject, $config);
	}


	/**
	 * Создание категории
	 * @param $element
	 */
	public function onAfterCategoryCreate(&$element)
	{
		$this->sync();
	}


	/**
	 * Обновление категории
	 * @param $element
	 */
	public function onAfterCategoryUpdate(&$element)
	{
		$this->sync();
	}


	/**
	 * Удаление категории
	 * @param $ids
	 */
	public function onAfterCategoryDelete(&$ids)
	{
		$this->sync();
	}


	/**
	 * После создания продукта
	 * @param $element
	 * @param $do
	 */
	public function onAfterProductCreate(&$element)
	{
		$this->generateCanonicalForProduct($element);
	}


	/**
	 * После обновление продукта
	 * @param $element
	 * @param $do
	 */
	public function onAfterProductUpdate(&$element)
	{
		$this->generateCanonicalForProduct($element);
	}


	/**
	 *
	 */
	public function onAjaxSynhikashopwithmenu()
	{
		$app = Factory::getApplication();
		$task = $app->input->getCmd('task');
		$backPage = $app->input->getString('backPage');

		if($task === 'sync')
		{
			$this->sync();
		}

		if($task === 'syncAndTrash')
		{
			$this->trashMenu();
			$this->sync();
		}

		if($task === 'checkUrlsAllForProducts')
		{
			$this->generateCanonicalForProduct();
		}

		$app->redirect($backPage);

	}


	/**
	 * Проверка адресов для всех продуктов
	 */
	protected function checkUrlsAllForProducts()
	{
		//выбираем продукты
		//запускаем проверку (метод generateUrlsForProduct)
	}


	/**
	 * Генерация канонических адреса и алиаса для продукта
	 * @param $element
	 */
	protected function generateCanonicalForProduct(&$element)
	{
		//если у продукта несколько категорий, то выбираем первую

		//создаем канонический адрес
		if((int)$this->params->get('autourls', 0) && empty($element->product_canonical))
		{
			//выбираем связку
			$db = Factory::getDbo();
			$query = $db
				->getQuery(true)
				->select(['hc.category_id', 'hc.category_canonical'])
				->from($db->quoteName('#__hikashop_category', 'hc'))
				->innerJoin( $db->quoteName('#__hikashop_product_category', 'hpc') . ' ON ' . $db->quoteName('hpc.category_id') . ' = ' . $db->quoteName('hc.category_id'))
				->where($db->quoteName('product_id') . ' = ' . (int)$element->product_id)
				->order('ordering DESC')
				->setLimit(1);
			$db->setQuery($query);
			$category = $db->loadObject();

			include_once rtrim(JPATH_ADMINISTRATOR,DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'com_hikashop' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'helper.php';
			$configClassHikashop = hikashop_get('class.config');
			$configClassHikashop->load();
			$productPrefix = $configClassHikashop->get('product_sef_name');
			$fullAlias = $category->category_canonical . '/' . $productPrefix . '/' . $element->product_alias;
			$fullAlias = str_replace('//', '/', $fullAlias);
			$this->updateProduct($element->product_id, [
				'product_canonical' => $fullAlias
			]);

		}



	}


	/**
	 * Обновление продукта
	 *
	 * @param $id
	 * @param $fieldsSource
	 * @return mixed
	 */
	protected function updateProduct($id, $fieldsSource)
	{
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		$fields = [];
		foreach ($fieldsSource as $key => $value)
		{
			$fields[] = $db->quoteName($key) . ' = ' . $db->quote($value);
		}
		$conditions = array(
			$db->quoteName('product_id') . ' = ' . (int)$id,
		);
		$query->update($db->quoteName('#__hikashop_product'))->set($fields)->where($conditions);
		$db->setQuery($query);
		return $db->execute();
	}



	//алгоритм сравнивания
	//проходим по категориям
	//если найден пункт меню с категорий, то сравниваем, если есть различия
	//(смотрим по названию и алиасу, создавать ли редиректы при смене алиаса?), то обновнляем пункт меню

	//если не найден пункт меню, а категория существует, то создаем пункт меню
	//если пункт меню есть, а категории нет, то удаляем пункт меню

	/**
	 * Синхронизация меню с категориями
	 */
	protected function sync()
	{
		$menutype = 0;
		$rootMenuId = 0;
		$menuItems = [];
		$typeInit = $this->params->get('typeinit', 'menu');

		//выбираем меню
		if($typeInit === 'menu')
		{
			$menuIdFromConfig = $this->params->get('syncmenu', 'nomenu');
			$menutype = $menuIdFromConfig;

			if($menutype === 'nomenu')
			{
				$this->app->enqueueMessage(Text::_('PLG_SYNHIKASHOPWITHMENU_ERROR_NOMENU'), 'error');
				return false;
			}
		}


		//выбираем меню
		$menuTable = JTableNested::getInstance('Menu');
		$menuTable->load([
			'menutype' => $menutype
		]);

		//выбираем пункты меню
		$db = Factory::getDbo();
		$query = $db
			->getQuery(true)
			->select('*')
			->from($db->quoteName('#__menu'))
			->where($db->quoteName('menutype') . ' = ' . $db->quote($menutype));
		$db->setQuery($query);
		$menuItemsSource = $db->loadObjectList();

		//выбираем образец пункта меню
		$db = Factory::getDbo();
		$query = $db
			->getQuery(true)
			->select('*')
			->from($db->quoteName('#__menu'))
			->where($db->quoteName('id') . ' = ' . (int)$this->params->get('samplemenuitem'));
		$db->setQuery($query);
		$sampleMenuItem = $db->loadObject();
		$sampleMenuItem->params = json_decode($sampleMenuItem->params, JSON_OBJECT_AS_ARRAY);

		//выбираем каталоги
		include_once rtrim(JPATH_ADMINISTRATOR,DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'com_hikashop' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'helper.php';
		$categoryClassHikashop = hikashop_get('class.category');
		$categoryItems = $categoryClassHikashop->getList();

		//подготавливаем дерево пунктов меню для синхронизации
		foreach ($menuItemsSource as $menuItemSource)
		{
			$paramsItemSource = json_decode($menuItemSource->params, JSON_OBJECT_AS_ARRAY);
			$menuItemSource->params = $paramsItemSource;
			iF(isset($paramsItemSource['hk_product']))
			{
				$categoryId = (int)$paramsItemSource['hk_product']['category'];
				$menuItems[$categoryId] = (array)$menuItemSource;
			}
		}

		//очищаем не нужную переменную
		unset($menuItemsSource);

		//отсортируем по уровню, чтобы не попадать в ситуацию, когда не существует родителя, чтобы не усложнять алгоритм на поиск предков
		$level  = array_column($categoryItems, 'category_depth');
		$category_ordering = array_column($categoryItems, 'category_ordering');
		array_multisort($level, SORT_ASC, $category_ordering, SORT_ASC, $categoryItems);

		foreach ($categoryItems as $categoryItem)
		{
			if((int)$categoryItem->category_id === 1)
			{
				continue;
			}

			$this->syncMenuItem($menuItems, $categoryItem, $sampleMenuItem, $menutype);

		}

		$this->cleanMenu($menuItems, $categoryItems);

		$this->messagesBuild();

		$menuTable->rebuild();

	}


	/**
	 * Этот метод создает или обновляет пункт меню в зависимости от категории
	 *
	 * @param $menuItems
	 * @param $categoryItem
	 * @param $sampleMenuItem
	 * @param $menutype
	 */
	protected function syncMenuItem(&$menuItems, $categoryItem, &$sampleMenuItem, $menutype)
	{
		if(isset($menuItems[(int)$categoryItem->category_id]))
		{
			//обновление записи
			$flagUpdate = false;
			$menuItemUpdate = $menuItems[(int)$categoryItem->category_id];

			//TODO спросить надо ли синхронизировать params
			//запуск поиска различий в парараметрах


			if(isset($menuItems[(int)$categoryItem->category_parent_id]))
			{
				$menuItemUpdateParent = $menuItems[(int)$categoryItem->category_parent_id];

				//проверяем поменялась ли родительская категория
				if((int)$menuItemUpdateParent['params']['hk_product']['category'] !== (int)$categoryItem->category_parent_id)
				{
					$flagUpdate = true;

				}
			}

			$note = explode(',', $menuItemUpdate['note']);

			//проверяем другие поля на измененные значения
			$fields = [
				'published' => $categoryItem->category_published,
				'title' => $categoryItem->category_name,
				'alias' => $categoryItem->category_alias,
			];

			foreach ($fields as $key => $value)
			{
				//если есть в примечаниях названия поля, значит оно перезаписано, не трогаем его
				if(in_array($key, $note))
				{
					continue;
				}

				if($menuItemUpdate[$key] !== $value)
				{
					$flagUpdate = true;
					$menuItemUpdate[$key] = $value;
				}
			}

			//сохраняем, если есть изменения
			if($flagUpdate)
			{
				$menuTable = JTableNested::getInstance('Menu');
				if ($menuTable->save($menuItemUpdate))
				{
					$this->addMessageCount('updateItem');
				}
			}

		}
		else
		{

			//создаем пункт меню

			$menuItemNew = (array)clone $sampleMenuItem;

			foreach ($menuItemNew as $keyAttr => $attr)
			{

				if(in_array($keyAttr, [
					'id',
					'route',
					'level',
					'tree',
					'query',
					'parent_id',
				]))
				{
					unset($menuItemNew[$keyAttr]);
				}
			}

			if(isset($categoryItem->menu_anchor_css) && $categoryItem->menu_anchor_css !== '')
			{
				$menuItemNew['params']['menu-anchor_css'] = $categoryItem->menu_anchor_css;
			}

			if(isset($categoryItem->menu_show) && $categoryItem->menu_show !== '')
			{
				$menuItemNew['params']['menu_show'] = $categoryItem->menu_show;
			}

			$menuItemNew['params']['hk_product']['category'] = $categoryItem->category_id;
			$menuItemNew['params'] = json_encode($menuItemNew['params']);
			$menuItemNew['menutype'] = $menutype;
			$menuItemNew['published'] = $categoryItem->category_published;
			$menuItemNew['title'] = $categoryItem->category_name;
			$menuItemNew['alias'] = $categoryItem->category_alias;

			$menuTable = JTableNested::getInstance('Menu');

			if(isset($menuItems[(int)$categoryItem->category_parent_id]))
			{
				$menuParent = $menuItems[(int)$categoryItem->category_parent_id];
				$menuTable->setLocation($menuParent['id'], 'last-child');
			}
			else
			{
				$menuTable->setLocation(1, 'last-child');
			}

			if ($menuTable->save($menuItemNew))
			{
				$this->addMessageCount('createItem');
			}

			$menuItemNew['id'] = $menuTable->id;
			$menuItemNew['params'] = json_decode($menuItemNew['params'], JSON_OBJECT_AS_ARRAY);

			$menuItems[(int)$categoryItem->category_id] = $menuItemNew;
		}
	}


	/**
	 * Удаляет лишние пункты меню, если удалены их категории
	 */
	protected function cleanMenu(&$menuItems, &$categories)
	{
		$ids = [];
		foreach ($categories as $category)
		{
			$ids[] = (int)$category->category_id;
		}

		foreach ($menuItems as $menuItem)
		{
			if(!in_array((int)$menuItem['params']['hk_product']['category'], $ids, true))
			{
				//удаляем пункт меню

				$db = Factory::getDbo();
				$query = $db->getQuery(true);
				$conditions = [
					$db->quoteName('id') . ' = ' . (int)$menuItem['id']
				];
				$query->delete($db->quoteName('#__menu'));
				$query->where($conditions);
				$db->setQuery($query);
				$db->execute();

				$this->addMessageCount('deleteItem');

			}
		}
	}


	/**
	 * Очищает от всех пунктов меню
	 */
	protected function trashMenu()
	{
		$menuIdFromConfig = $this->params->get('syncmenu', 'nomenu');
		$menutype = $menuIdFromConfig;

		if($menutype === 'nomenu')
		{
			$this->app->enqueueMessage(Text::_('PLG_SYNHIKASHOPWITHMENU_ERROR_NOMENU'), 'error');
			return false;
		}

		$db = Factory::getDbo();
		$query = $db->getQuery(true);
		$conditions = [
			$db->quoteName('menutype') . ' = ' . $db->quote($menutype)
		];
		$query->delete($db->quoteName('#__menu'));
		$query->where($conditions);
		$db->setQuery($query);
		$db->execute();
		$this->app->enqueueMessage(Text::_('PLG_SYNHIKASHOPWITHMENU_TRASHMENU'));

		//TODO спросить при очистке надо ли найти старые пункты меню и заменить их в базе данных

	}


	/**
	 * Добавляем счетчик к сообщениям
	 * @param $type
	 */
	protected function addMessageCount($type)
	{
		if(!isset($this->messages[$type]))
		{
			$this->messages[$type] = 0;
		}

		$this->messages[$type]++;

	}


	/**
	 * Собираем сообщения в общее для уведомления
	 */
	protected function messagesBuild()
	{
		$messageOutput = '';

		foreach ($this->messages as $type => $count)
		{
			$messageOutput .= Text::_('PLG_SYNHIKASHOPWITHMENU_MESSAGE_' . strtoupper($type)) . ': ' . $count . '<br/>';
		}

		$this->app->enqueueMessage($messageOutput);
	}


	/**
	 * Добавляем кнопки в тулбар
	 *
	 * @throws  Exception
	 *
	 * @since  1.0.0
	 */
	public function onHikashopAfterDisplayView(&$viewHikashop)
	{
		$app          = Factory::getApplication();
		$admin        = $app->isClient('administrator');
		$option       = $app->input->getCmd('option');
		$ctrl         = $app->input->getCmd('ctrl');

		if(
			$admin &&
			$option === 'com_hikashop' &&
			$ctrl === 'product'
		)
		{
			$toolbar = Toolbar::getInstance('toolbar');
			$items = $toolbar->getItems();

			if(count($items) > 0)
			{
				$root    = Uri::getInstance()->toString(array('scheme', 'host', 'port'));

				$url = $root . '/administrator/index.php?' . http_build_query([
						'option' => 'com_ajax',
						'plugin' => 'synhikashopwithmenu',
						'group' => 'hikashop',
						'format' => 'raw',
						'task' => 'checkUrlsAllForProducts',
						'backPage' => $_SERVER['REQUEST_URI']
					]);

				$button = '<a href="' . $url . '" class="btn btn-small">'
					. '<span class="icon-refresh" aria-hidden="true"></span>'
					. Text::_('PLG_SYNHIKASHOPWITHMENU_BUTTON_URLS') . '</a>';
				$toolbar->appendButton('Custom', $button, 'generate');
			}

		}

		if(
			$admin &&
			$option === 'com_hikashop' &&
			$ctrl === 'category'
		)
		{

			$toolbar = Toolbar::getInstance('toolbar');
			$items = $toolbar->getItems();

			if(count($items) > 0)
			{
				//добавляем проверу от случайных нажатий на кнопку, которая очищает меню
				$message = Text::_('PLG_SYNHIKASHOPWITHMENU_MESSAGE_CONFIRMDELETE');
				Factory::getDocument()->addScriptDeclaration(<<<EON
document.addEventListener('DOMContentLoaded', function() {
    document.querySelector('.button-syncAndTrash').addEventListener('click', function(ev) {
    	ev.preventDefault();
			
		if (confirm('{$message}')) {
			window.location.href = this.getAttribute('href');
		}
    });
});
EON
				);

				$root = Uri::getInstance()->toString(array('scheme', 'host', 'port'));

				$url = $root . '/administrator/index.php?' . http_build_query([
						'option' => 'com_ajax',
						'plugin' => 'synhikashopwithmenu',
						'group' => 'hikashop',
						'format' => 'raw',
						'task' => 'sync',
						'backPage' => $_SERVER['REQUEST_URI']
					]);

				$button = '<a href="' . $url . '" class="btn btn-small">'
					. '<span class="icon-refresh" aria-hidden="true"></span>'
					. Text::_('PLG_SYNHIKASHOPWITHMENU_BUTTON_SYNC') . '</a>';
				$toolbar->appendButton('Custom', $button, 'generate');


				$url = $root . '/administrator/index.php?' . http_build_query([
						'option' => 'com_ajax',
						'plugin' => 'synhikashopwithmenu',
						'group' => 'hikashop',
						'format' => 'raw',
						'task' => 'syncAndTrash',
						'backPage' => $_SERVER['REQUEST_URI']
					]);

				$button = '<a href="' . $url . '" class="btn btn-small button-syncAndTrash">'
					. '<span class="icon-refresh" aria-hidden="true"></span>'
					. Text::_('PLG_SYNHIKASHOPWITHMENU_BUTTON_SYNC_AND_CLEAN') . '</a>';
				$toolbar->appendButton('Custom', $button, 'generate');
			}

		}
	}


}