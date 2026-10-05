<?php
/**
 * RichTextParserGuard for MODX Revolution 2.x
 *
 * Protects RichText content from accidentally pasted MODX/Fenom syntax.
 *
 * Handles:
 * - resource.content when richtext = 1
 * - standard MODX TVs with type = richtext
 * - Polylang 1.3.x content fields rendered with polylang-text-editor
 * - Polylang richtext TVs
 *
 * Events:
 * - OnBeforeDocFormSave
 * - OnDocFormSave
 * - OnMODXInit
 *
 * When pasting this file into the MODX plugin editor, remove the opening <?php tag.
 */

$removeHtmlComments = true;
$debug = false;

$sanitizeRichText = function ($value) use ($removeHtmlComments) {
    if (!is_string($value) || $value === '') {
        return $value;
    }

    if ($removeHtmlComments) {
        $value = preg_replace('~<!--.*?-->~s', '', $value);
    }

    $value = str_replace(
        array('[[', ']]'),
        array('&#91;&#91;', '&#93;&#93;'),
        $value
    );

    $value = str_replace(
        array('{', '}'),
        array('&#123;', '&#125;'),
        $value
    );

    return $value;
};

$logChange = function ($type, $name, $id = 0) use ($modx, $debug) {
    if (!$debug) {
        return;
    }

    $message = '[RichTextParserGuard] Cleaned ' . $type;

    if ($name !== '') {
        $message .= ': ' . $name;
    }

    if ($id) {
        $message .= ' | resource=' . (int)$id;
    }

    $modx->log(modX::LOG_LEVEL_INFO, $message);
};

switch ($modx->event->name) {
    case 'OnBeforeDocFormSave':
        if (!isset($resource) || !is_object($resource)) {
            break;
        }

        if ((int)$resource->get('richtext') !== 1) {
            break;
        }

        $oldValue = (string)$resource->get('content');
        $newValue = $sanitizeRichText($oldValue);

        if ($newValue !== $oldValue) {
            $resource->set('content', $newValue);

            $logChange(
                'resource field',
                'content',
                (int)$resource->get('id')
            );
        }

        break;

    case 'OnDocFormSave':
        if (!isset($resource) || !is_object($resource)) {
            break;
        }

        $resourceId = (int)$resource->get('id');

        if (!$resourceId) {
            break;
        }

        $q = $modx->newQuery('modTemplateVarResource');

        $q->innerJoin(
            'modTemplateVar',
            'TV',
            'TV.id = modTemplateVarResource.tmplvarid'
        );

        $q->innerJoin(
            'modTemplateVarTemplate',
            'TVTemplate',
            'TVTemplate.tmplvarid = TV.id'
        );

        $q->where(array(
            'modTemplateVarResource.contentid' => $resourceId,
            'TV.type' => 'richtext',
            'TVTemplate.templateid' => (int)$resource->get('template'),
        ));

        $tvValues = $modx->getCollection('modTemplateVarResource', $q);

        foreach ($tvValues as $tvValue) {
            $oldValue = (string)$tvValue->get('value');
            $newValue = $sanitizeRichText($oldValue);

            if ($newValue === $oldValue) {
                continue;
            }

            $tvValue->set('value', $newValue);
            $tvValue->save();

            $tv = $modx->getObject(
                'modTemplateVar',
                (int)$tvValue->get('tmplvarid')
            );

            $tvName = $tv
                ? $tv->get('name')
                : 'TV #' . $tvValue->get('tmplvarid');

            $logChange('TV', $tvName, $resourceId);
        }

        break;

    case 'OnMODXInit':
        $action = isset($_REQUEST['action'])
            ? strtolower(trim((string)$_REQUEST['action'], '/'))
            : '';

        if (!in_array($action, array(
            'mgr/polylangcontent/create',
            'mgr/polylangcontent/update',
        ), true)) {
            break;
        }

        $corePath = $modx->getOption(
            'polylang.core_path',
            null,
            $modx->getOption('core_path') . 'components/polylang/'
        );

        $classFile = $corePath . 'model/polylang/polylang.class.php';

        if (!class_exists('Polylang', false)) {
            if (!file_exists($classFile)) {
                if ($debug) {
                    $modx->log(
                        modX::LOG_LEVEL_ERROR,
                        '[RichTextParserGuard] Polylang class file not found: ' . $classFile
                    );
                }

                break;
            }

            require_once $classFile;
        }

        if (!isset($modx->polylang) || !($modx->polylang instanceof Polylang)) {
            $modx->polylang = new Polylang($modx);
        }

        $changedFields = array();

        $sanitizePostField = function ($key) use (
            $sanitizeRichText,
            &$changedFields
        ) {
            if (isset($_POST[$key]) && is_string($_POST[$key])) {
                $oldValue = $_POST[$key];
                $newValue = $sanitizeRichText($oldValue);

                if ($newValue !== $oldValue) {
                    $_POST[$key] = $newValue;
                    $_REQUEST[$key] = $newValue;
                    $changedFields[] = $key;
                }

                return;
            }

            if (isset($_REQUEST[$key]) && is_string($_REQUEST[$key])) {
                $oldValue = $_REQUEST[$key];
                $newValue = $sanitizeRichText($oldValue);

                if ($newValue !== $oldValue) {
                    $_REQUEST[$key] = $newValue;
                    $changedFields[] = $key;
                }
            }
        };

        $polylangFields = $modx->getCollection(
            'PolylangField',
            array(
                'active' => 1,
                'xtype' => 'polylang-text-editor',
            )
        );

        foreach ($polylangFields as $field) {
            $name = trim((string)$field->get('name'));

            if ($name === '') {
                continue;
            }

            $sanitizePostField($name);
            $sanitizePostField('polylangcontent_' . $name);
        }

        $richTvs = $modx->getCollection(
            'modTemplateVar',
            array('type' => 'richtext')
        );

        foreach ($richTvs as $tv) {
            $tvId = (int)$tv->get('id');
            $tvName = trim((string)$tv->get('name'));

            $keys = array(
                'tv' . $tvId,
                'tv_' . $tvId,
            );

            if ($tvName !== '') {
                $keys[] = $tvName;
                $keys[] = 'polylangcontent_' . $tvName;
            }

            foreach ($keys as $key) {
                $sanitizePostField($key);
            }

            foreach (array('tv', 'tvs', 'polylangtv') as $bucket) {
                if (!isset($_POST[$bucket]) || !is_array($_POST[$bucket])) {
                    continue;
                }

                foreach (array($tvId, (string)$tvId, $tvName) as $subKey) {
                    if (
                        $subKey === ''
                        || !isset($_POST[$bucket][$subKey])
                        || !is_string($_POST[$bucket][$subKey])
                    ) {
                        continue;
                    }

                    $oldValue = $_POST[$bucket][$subKey];
                    $newValue = $sanitizeRichText($oldValue);

                    if ($newValue === $oldValue) {
                        continue;
                    }

                    $_POST[$bucket][$subKey] = $newValue;

                    if (
                        isset($_REQUEST[$bucket])
                        && is_array($_REQUEST[$bucket])
                    ) {
                        $_REQUEST[$bucket][$subKey] = $newValue;
                    }

                    $changedFields[] = $bucket . '[' . $subKey . ']';
                }
            }
        }

        if ($debug) {
            $modx->log(
                modX::LOG_LEVEL_INFO,
                '[RichTextParserGuard] Polylang action: '
                . $action
                . ' | changed: '
                . (
                    !empty($changedFields)
                        ? implode(', ', array_unique($changedFields))
                        : 'NONE'
                )
                . ' | POST keys: '
                . implode(', ', array_keys($_POST))
            );
        }

        break;
}
