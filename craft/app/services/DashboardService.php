<?php
namespace Craft;

/**
 * Craft by Pixel & Tonic
 *
 * @package   Craft
 * @author    Pixel & Tonic, Inc.
 * @copyright Copyright (c) 2013, Pixel & Tonic, Inc.
 * @license   http://buildwithcraft.com/license Craft License Agreement
 * @link      http://buildwithcraft.com
 */

/**
 *
 */
class DashboardService extends BaseApplicationComponent
{
	/**
	 * Returns all installed widget types.
	 *
	 * @return array
	 */
	public function getAllWidgetTypes()
	{
		return craft()->components->getComponentsByType(ComponentType::Widget);
	}

	/**
	 * Returns a widget type.
	 *
	 * @param string $class
	 * @return BaseWidget|null
	 */
	public function getWidgetType($class)
	{
		return craft()->components->getComponentByTypeAndClass(ComponentType::Widget, $class);
	}

	/**
	 * Populates a widget type.
	 *
	 * @param WidgetModel $widget
	 * @return BaseWidget|null
	 */
	public function populateWidgetType(WidgetModel $widget)
	{
		return craft()->components->populateComponentByTypeAndModel(ComponentType::Widget, $widget);
	}

	/**
	 * Returns the dashboard widgets for the current user.
	 *
	 * @param string|null $indexBy
	 * @return array
	 */
	public function getUserWidgets($indexBy = null)
	{
		$widgetRecords = $this->_getUserWidgetRecords();

		// If there are no widget records, this is the first time they've hit the dashboard.
		if (!$widgetRecords)
		{
			// Add the defaults and try again
			$this->_addDefaultUserWidgets();
			$widgetRecords = $this->_getUserWidgetRecords();
		}
		else
		{
			// Get only the enabled widgtes.
			foreach ($widgetRecords as $key => $widgetRecord)
			{
				if (!$widgetRecord->enabled)
				{
					unset($widgetRecords[$key]);
				}
			}
		}

		if (count($widgetRecords) > 0)
		{
			return WidgetModel::populateModels($widgetRecords, $indexBy);
		}

		return array();
	}

	/**
	 * Returns a widget by its ID.
	 *
	 * @param int $id
	 * @return WidgetModel
	 */
	public function getUserWidgetById($id)
	{
		$widgetRecord = WidgetRecord::model()->findByAttributes(array(
			'id' => $id,
			'userId' => craft()->userSession->getUser()->id
		));

		if ($widgetRecord)
		{
			return WidgetModel::populateModel($widgetRecord);
		}
	}

	/**
	 * Saves a widget for the current user.
	 *
	 * @param WidgetModel $widget
	 * @return bool
	 */
	public function saveUserWidget(WidgetModel $widget)
	{
		$widgetRecord = $this->_getUserWidgetRecordById($widget->id);
		$widgetRecord->type = $widget->type;

		$widgetType = $this->populateWidgetType($widget);
		$processedSettings = $widgetType->prepSettings($widget->settings);
		$widgetRecord->settings = $widget->settings = $processedSettings;
		$widgetType->setSettings($processedSettings);

		$recordValidates = $widgetRecord->validate();
		$settingsValidate = $widgetType->getSettings()->validate();

		if ($recordValidates && $settingsValidate)
		{
			if ($widgetRecord->isNewRecord())
			{
				$maxSortOrder = craft()->db->createCommand()
					->select('max(sortOrder)')
					->from('widgets')
					->queryScalar();

				$widgetRecord->sortOrder = $maxSortOrder + 1;
			}

			$widgetRecord->save(false);

			// Now that we have a widget ID, save it on the model
			if (!$widget->id)
			{
				$widget->id = $widgetRecord->id;
			}

			return true;
		}
		else
		{
			$widget->addErrors($widgetRecord->getErrors());
			$widget->addSettingErrors($widgetType->getSettings()->getErrors());

			return false;
		}
	}

	/**
	 * Soft deletes a widget.
	 *
	 * @param int $widgetId
	 * @return bool
	 */
	public function deleteUserWidgetById($widgetId)
	{
		$widgetRecord = $this->_getUserWidgetRecordById($widgetId);
		$widgetRecord->enabled = false;
		$widgetRecord->save();

		return true;
	}

	/**
	 * Reorders widgets.
	 *
	 * @param array $widgetIds
	 * @throws \Exception
	 * @return bool
	 */
	public function reorderUserWidgets($widgetIds)
	{
		$transaction = craft()->db->beginTransaction();

		try
		{
			foreach ($widgetIds as $widgetOrder => $widgetId)
			{
				$widgetRecord = $this->_getUserWidgetRecordById($widgetId);
				$widgetRecord->sortOrder = $widgetOrder+1;
				$widgetRecord->save();
			}

			$transaction->commit();
		}
		catch (\Exception $e)
		{
			$transaction->rollBack();
			throw $e;
		}

		return true;
	}

	/**
	 * Adds the default widgets to the logged-in user.
	 *
	 * @access private
	 */
	private function _addDefaultUserWidgets()
	{
		$user = craft()->userSession->getUser();
		$sections = craft()->sections->getAllSections();

		foreach ($sections as $section)
		{
			// Only add widgets for sections they have create privileges to.
			if ($user->can('createEntries:'.$section->id))
			{
				$widget = new WidgetModel();
				$widget->type = 'QuickPost';

				$widget->settings = array(
					'section' => $section->id
				);

				$this->saveUserWidget($widget);
			}
		}

		// Recent Entries widget
		$widget = new WidgetModel();
		$widget->type = 'RecentEntries';
		$this->saveUserWidget($widget);

		// Get Help widget
		if ($user->admin)
		{
			$widget = new WidgetModel();
			$widget->type = 'GetHelp';
			$this->saveUserWidget($widget);
		}

		// Updates widget
		if ($user->can('performupdates'))
		{
			$widget = new WidgetModel();
			$widget->type = 'Updates';
			$this->saveUserWidget($widget);
		}

		// Blog & Tonic feed widget
		$widget = new WidgetModel();
		$widget->type = 'Feed';
		$widget->settings = array(
			'url'   => 'http://feeds.feedburner.com/blogandtonic',
			'title' => 'Blog & Tonic'
		);
		$this->saveUserWidget($widget);
	}

	/**
	 * Gets a widget's record.
	 *
	 * @access private
	 * @param int $widgetId
	 * @return WidgetRecord
	 */
	private function _getUserWidgetRecordById($widgetId = null)
	{
		$userId = craft()->userSession->getUser()->id;

		if ($widgetId)
		{
			$widgetRecord = WidgetRecord::model()->findByAttributes(array(
				'id'     => $widgetId,
				'userId' => $userId
			));

			if (!$widgetRecord)
			{
				$this->_noWidgetExists($widgetId);
			}
		}
		else
		{
			$widgetRecord = new WidgetRecord();
			$widgetRecord->userId = $userId;
		}

		return $widgetRecord;
	}

	/**
	 * Throws a "No widget exists" exception.
	 *
	 * @access private
	 * @param int $widgetId
	 * @throws Exception
	 */
	private function _noWidgetExists($widgetId)
	{
		throw new Exception(Craft::t('No widget exists with the ID “{id}”', array('id' => $widgetId)));
	}

	/**
	 * Returns the widget records for the current user.
	 *
	 * @access private
	 * @return array
	 */
	private function _getUserWidgetRecords()
	{
		return WidgetRecord::model()->ordered()->findAllByAttributes(array(
			'userId' => craft()->userSession->getUser()->id
		));
	}
}
