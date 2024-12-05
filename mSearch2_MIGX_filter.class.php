<?php
// Делаем дополнение для пакета mSearch2 которое позволит нам делать фильтр по TV полям с типом MIGX
// Шаги:
// 1) Для этого идем в core/components/msearch2/custom/filters/ и создаем например mSearch2_MIGX_filter.class.php
// 2) Затем в настройках mSearch2 изменяем настройку с ключом mse2_filters_handler_class - прописываем mSearch2_MIGX_filter.class.php 
// 3) идем в сам файл mSearch2_MIGX_filter.class.php

class mSearch2_MIGX_filter extends mse2FiltersHandler
{ 
    public function getMigxValues(array $tvs, array $ids) {
        $filters = array();
        $q = $this->modx->newQuery('modResource', array('modResource.id:IN' => $ids));
        $q->leftJoin('modTemplateVarTemplate', 'TemplateVarTemplate',
            'TemplateVarTemplate.tmplvarid IN (SELECT id FROM ' . $this->modx->getTableName('modTemplateVar') . ' WHERE name IN ("' . implode('","', $tvs) . '") )
            AND modResource.template = TemplateVarTemplate.templateid'
        );
        $q->leftJoin('modTemplateVar', 'TemplateVar', 'TemplateVarTemplate.tmplvarid = TemplateVar.id');
        $q->leftJoin('modTemplateVarResource', 'TemplateVarResource', 'TemplateVarResource.tmplvarid = TemplateVar.id AND TemplateVarResource.contentid = modResource.id');
        $q->select('TemplateVar.name, modResource.id as id, TemplateVarResource.value, TemplateVar.type, TemplateVar.default_text');
        $tstart = microtime(true);
        if ($q->prepare() && $q->stmt->execute()) {
            $this->modx->queryTime += microtime(true) - $tstart;
            $this->modx->executedQueries++;
            while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
                if (empty($row['id'])) {
                    continue;
                }
                
                elseif (is_null($row['value']) || trim($row['value']) == '') {
                    $row['value'] = $row['default_text'];
                }
                
                if ($row['type'] == 'migx') {
                    $jsonData = json_decode($row['value'], true);
                    if (json_last_error() == JSON_ERROR_NONE && is_array($jsonData)) {
                        foreach ($jsonData as $migxItem) {

                            // Здесь важно указать по какому полю из вашего MIGX будем делать фильтр
                          
                            // Фильтруем только поля title
                            if (isset($migxItem['title'])) {
                                $title = str_replace('"', '&quot;', trim($migxItem['title']));
                                if ($title == '') {
                                    continue;
                                }
                                $name = strtolower($row['name']); // Просто используем имя TV как название фильтра
                                if (isset($filters[$name][$title])) {
                                    $filters[$name][$title][$row['id']] = $row['id'];
                                    
                                } else {
                                    $filters[$name][$title] = array($row['id'] => $row['id']);
                                }
                            }
                            // Фильтруем только поля date
                            if (isset($migxItem['date'])) {
                                $title = str_replace('"', '&quot;', trim($migxItem['date']));
                                if ($title == '') {
                                    continue;
                                }
                                $name = strtolower($row['name']); // Просто используем имя TV как название фильтра
                                if (isset($filters[$name][$title])) {
                                    $filters[$name][$title][$row['id']] = $row['id'];
                                } else {
                                    $filters[$name][$title] = array($row['id'] => $row['id']);
                                }
                            }
                            
                            // Если нужно сделать второй фильтр из одного и того же TV MIGX но по другому полю:
                            // Фильтруем только поля value
                            if (isset($migxItem['value'])) {
                                $title = str_replace('"', '&quot;', trim($migxItem['value']));
                                if ($title == '') {
                                    continue;
                                }
                                // Задаем новое название фильтра для этого поля чтобы оно отличалось от того которое уже использовали
                                $customName = strtolower('accommodation_value');
                             
                                if (isset($filters[$customName][$title])) {
                                    $filters[$customName][$title][$row['id']] = $row['id'];
                                } else {
                                    $filters[$customName][$title] = array($row['id'] => $row['id']);
                                }               
                            }
                        }
                    }
                }   
            }
        }
        else {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[mSearch2] Error on get filter params.\nQuery: ".$q->toSQL()."\nResponse: ".print_r($q->stmt->errorInfo(),1));
        }
        
        return $filters;
    }
    public function buildMigxFilter(array $values, $name = '') {

        $keys = array_keys($values);
        if (empty($keys) || (count($keys) < 2 && empty($this->config['showEmptyFilters']))) {
            return array();
        }

        $results = $names = array();
        $q = $this->modx->newQuery('modTemplateVar', array('name' => $name));
        $q->select('elements');
        $tstart = microtime(true);
        if ($q->prepare() && $q->stmt->execute()) {
            $this->modx->queryTime += microtime(true) - $tstart;
            $this->modx->executedQueries++;
            //$tmp = $q->stmt->fetchColumn();
            $names = array();
        }
       
        foreach ($values as $value => $ids) {
            if ($value !== '') {
                
                $results[] = array(
                    'title' => $value,
                    'value' => $value,
                    'type' => 'tv',
                    'resources' => $ids
                );
            }
        }

        $nameParts = explode('|', $name); // Попытка разделить по '|'
        $filterName = isset($nameParts[1]) ? $nameParts[1] : strtolower($name);
        
        return $this->sortFilters($results, 'tv', array('name' => $filterName));


    }
    public function filterMigx(array $requested, array $values, array $ids) {
        $matched = array();
        $tmp = array_flip($ids);
    
        $filteredValues = array();
        foreach ($values as $value => $ids) {
            $filteredValues[$value] = $ids;
        }
    
        foreach ($requested as $value) {
            $value = str_replace('"', '&quot;', $value);
            if (isset($filteredValues[$value])) {
                $resources = $filteredValues[$value];
                foreach ($resources as $id) {
                    if (isset($tmp[$id])) {
                        $matched[] = $id;
                    }
                }
            }
        }
        return $matched;
    }

}

// В результате мы получаем возможность в сниппете добавить кастомный фильтр по MIGX
// А также добавить им разные шаблоны и методы обработки, так например в моем примере есть одно TV поле TourAccommodation из которого мы делаем фильтр по полю title, а для фильтрации по полю value мы создали фильтр accommodation_value. Благодаря этому мы можем назначить им разные шаблоны им методы
//        {'!mFilter2' | snippet : [
//                 'element' => 'mSearch2',
//                 ...
//                 'filters' => '
//                    ...
//                    migx|TourAccommodation:migx,
//                    migx|accommodation_value:number',
//                    'tplFilter.row.migx|TourAccommodation' => 'tpl.mFilter2.filter.checkbox_accomm',
//                    'tplFilter.outer.migx|accommodation_value' => 'tpl.mFilter2.filter.slider_price',
//                    'tplFilter.row.migx|accommodation_value' => 'tpl.mFilter2.filter.number_price',
//                    ]}

