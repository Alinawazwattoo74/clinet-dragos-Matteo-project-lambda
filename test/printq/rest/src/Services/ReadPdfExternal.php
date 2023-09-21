<?php

namespace Printq\Rest\Services;

class ReadPdfExternal
{
    
    protected $pdf            = null;
    protected $doc            = null;
    protected $custom_options = null;
    protected $editorConfig  = null;
    protected $options        = null;
    protected $type           = null;
    
    public function __construct($pdf, $doc, $params)
    {
        $this->pdf            = $pdf;
        $this->doc            = $doc;
        $result               = [];
        $this->editorConfig  = isset($params['editor_config']) ? $params['editor_config'] : [];
        $this->custom_options = isset($params['custom_options']) ? $params['custom_options'] : [];
        $this->input          = isset($params['input']) ? $params['input'] : '';
        $this->type           = isset($params['type']) ? $params['type'] : null;
        $this->options        = isset($params['options']) ? $params['options'] : null;

        return $result;
    }
    
    public function handleRequest()
    {
        $result = [];
        switch ($this->type) {
            case 'getBlocks':
                $result = $this->readBlocksDataFromPdf($this->doc, $this->options);
                break;
            case 'getPages':
                $result = $this->getNumberOfPages();
                break;
                case 'getBlocksInfo':
                $result = $this->getBlocksInfo();
                break;
            default:
                break;
        }
        return $result;
    }
    
    public function getNumberOfPages()
    {
        return $this->pdf->pcos_get_number($this->doc, "length:pages");
    }
    public function getBlocksInfo(){
        $pdf         = $this->pdf;
        $doc         =$this->doc;
        $page_number = $pdf->pcos_get_number($doc, "length:pages");
        $pages       = [];
        for ($i = 0; $i < $page_number; $i++) {
            $page         = [
                'name'   => ('Page ') . ($i + 1),
                'blocks' => []
            ];
            $blocksNumber = $pdf->pcos_get_number($doc, "length:pages[$i]/blocks");
            for ($j = 0; $j < $blocksNumber; $j++) {
                $name       = ucfirst($this->getBlockProp( $i, $j, 'Name', array('propType' => 'name')));
                $custom     = $pdf->pcos_get_number($doc, "length:pages[$i]/blocks[$j]/Custom");
                $blockType  = $this->getBlockProp( $i, $j, 'Subtype', array('propType' => 'name'));
                $isTextflow = $pdf->pcos_get_string($doc, "type:pages[$i]/blocks/" . $name . "/textflow") == "boolean"
                    && $pdf->pcos_get_string($doc, "pages[$i]/blocks/" . $name . "/textflow") == "true";
                if ($isTextflow) {
                    $blockType = "Textflow";
                }
                $customoptions = [];
                if ($custom) {
                    for ($k = 0; $k < $custom; $k++) {
                        $key                 = ucfirst(preg_replace('/[-_\s]+/', ' ', $pdf->pcos_get_string($doc, "pages[$i]/blocks[$j]/Custom[$k].key")));
                        $value               = preg_replace('/[-\s]+/', ' ', $pdf->pcos_get_string($doc, "pages[$i]/blocks[$j]/Custom[$k]"));
                        $customoptions[$key] = $value;
                    }
                    $block            = [
                        'name'          => $name,
                        'type'          => ucfirst($blockType),
                        'customoptions' => $customoptions
                    ];
                    $page['blocks'][] = $block;
                }
            
            }
           
            $pages[] = $page;
        
        }
      
        return $pages;
    }
    
    protected function readBlocksDataFromPDF($doc, $options)
    {
        
        $projectData = array(
            'pages'      => array(),
            'pagesOrder' => array(),
            'objects'    => array()
        );
        $totalPages  = $this->pdf->pcos_get_number($doc, "length:pages");
        $start_page = isset($options['start_page']) ? $options['start_page'] : 0;
        $end_page   = isset($options['end_page']) && $options['end_page'] ? $options['end_page'] : $totalPages;
        
        if (!$totalPages) {
            throw  new \Exception("Invalid pages number");
        }
        for ($i = $start_page; $i < $end_page; $i++) {
            $currentPage                     = array();
            $currentPage['id']               = 'page_' . $i;
            $currentPage['boxes']            = array();
            $currentPage['objectsIds']       = array();
            $currentPage['guides']           = array();
            $currentPage['width']            = $this->getPageProp($i, 'width', 'number');
            $currentPage['height']           = $this->getPageProp($i, 'height', 'number');
            $currentPage['boxes']['trimbox'] = array(
                'left'   => 0,
                'top'    => 0,
                'right'  => 0,
                'bottom' => 0
            );
            if ($this->getPagePropType($i, 'TrimBox') == 'array') {
                $currentPage['boxes']['trimbox'] = array(
                    'left'   => $this->getPageProp($i, 'TrimBox[0]'),
                    'bottom' => $this->getPageProp($i, 'TrimBox[1]'),
                    'right'  => $currentPage['width'] - $this->getPageProp($i, 'TrimBox[2]'),
                    'top'    => $currentPage['height'] - $this->getPageProp($i, 'TrimBox[3]'),
                );
            }
            $projectData['pages']['page_' . $i] = $currentPage;
            array_push($projectData['pagesOrder'], $currentPage['id']);
            $blocksNumber                                    = $this->pdf->pcos_get_number($this->doc, "length:pages[$i]/blocks");
            $pdfBlockName                                    = "background_page_" . $i;
            $pdf_block                                       = [
                'name'            => $pdfBlockName,
                'type'            => 'image',
                'subType'         => 'pdf',
                'left'            => 0,
                'top'             => 0,
                'pdfPage'         => $i,
                'editable'        => 0,
                'isPdfBackground' => 1,
                'width'           => $currentPage['width'],
                'height'          => $currentPage['height']
            ];
            $projectData['objects']["background_page_" . $i] = $pdf_block;
            array_push($projectData['pages']['page_' . $i]['objectsIds'], $pdfBlockName);
            for ($j = 0; $j < $blocksNumber; $j++) {
                $defaultsGeneral = isset($this->editorConfig['object']) && isset($this->editorConfig['object']['textflow']) ? $this->editorConfig['object']['textflow'] : [];
                $defaultsType    = array();
                $defaultsSubType = array();
                $blockData       = array();
                $type            = $this->getBlockPropType($i, $j, 'Subtype');
                $name            = $this->getBlockProp($i, $j, 'Name', array('propType' => 'name'));
                if ($type != 'name' || !$name) {
                    continue;
                }
                $isTextflow = $this->pdf->pcos_get_string($this->doc, "type:pages[$i]/blocks/" . $name . "/textflow") == "boolean"
                    && $this->pdf->pcos_get_string($this->doc, "pages[$i]/blocks/" . $name . "/textflow") == "true";
                $blockType  = strtolower($this->getBlockProp($i, $j, 'Subtype', array('propType' => 'name')));
                if (!$this->ValidateBlockName($name)) {
                    throw new \Exception(('Block ' . $name . ' from page ' . ($i + 1) . ' is not valid'));
                }
                if (array_key_exists($name, $projectData['objects'])) {
                    throw new \Exception(('Duplicated block name'));
                }
                // try and get custom properties for this block
                $custom = $this->pdf->pcos_get_number($this->doc, "length:pages[$i]/blocks[$j]/Custom");
                if ($custom) {
                    $this->validateCustomBlocks($this->pdf, $this->doc, $i, $j, $custom, $projectData,
                        $this->input, $name);
                    $customOptions = $this->getCustomOptions($projectData['objects'][$name]);
                    if (is_array($customOptions)) {
                        $blockData = array_merge($blockData, $customOptions);
                    }
                }
                //
                /// / add default values to json
                $isRotate      = $this->pdf->pcos_get_string($this->doc, "type:pages[$i]/blocks[$j]/rotate") == 'number'
                    && $this->pdf->pcos_get_string($this->doc,
                        "pages[$i]/blocks[$j]/rotate") != 0;
                $rotate        = $isRotate ? $this->pdf->pcos_get_string($this->doc, "pages[$i]/blocks[$j]/rotate") : 0;
                $isOpacityFill = $this->pdf->pcos_get_string($this->doc, "type:pages[$i]/blocks[$j]/opacityfill") == 'number'
                    && $this->pdf->pcos_get_string($this->doc,
                        "pages[$i]/blocks[$j]/opacityfill") != 0;
                $opacityfill   = $isOpacityFill ? $this->pdf->pcos_get_string($this->doc, "pages[$i]/blocks[$j]/opacityfill") : 1.00;
                
                $bgcolor_result     = $this->readTemplateColor($this->pdf, $this->doc, $i, $j, 'backgroundcolor');
                $bordercolor_result = $this->readTemplateColor($this->pdf, $this->doc, $i, $j, 'bordercolor');
                
                $orientate = 'north';
                if ($this->getBlockPropType($i, $j, 'orientate') == 'name') {
                    $orientate = $this->getBlockProp($i, $j, 'orientate', array('propType' => 'string'));
                }
                $borderwidth = 0;
                if ($this->getBlockPropType($i, $j, 'linewidth') == 'number') {
                    $borderwidth = (float)$this->getBlockProp($i, $j, 'linewidth', array('propType' => 'string'));
                }
                if ($borderwidth == 0 && !($bordercolor_result['colorspace'] == '' || $bordercolor_result['colorspace'] == 'None')) {
                    $borderwidth = 1;
                }
                $positions                    = [
                    'x1' => $this->getBlockProp($i, $j, 'Rect[0]',
                        array('propType' => 'number')),
                    'y1' => $this->getBlockProp($i, $j, 'Rect[1]',
                        array('propType' => 'number')),
                    'x2' => $this->getBlockProp($i, $j, 'Rect[2]',
                        array('propType' => 'number')),
                    'y2' => $this->getBlockProp($i, $j, 'Rect[3]',
                        array('propType' => 'number'))
                ];
                $blockData['id']              = $name;
                $blockData['name']            = $name;
                $blockData['width']           = $positions['x2'] - $positions['x1'];
                $blockData['height']          = $positions['y2'] - $positions['y1'];
                $blockData['left']            = $positions['x1'];
                $blockData['top']             = $currentPage['height'] - $positions['y2'];
                $blockData['opacity']         = $opacityfill;
                $blockData['borderWidth']     = $borderwidth;
                $blockData['orientate']       = $orientate;
                $blockData['rotateAngle']     = $rotate;
                $blockData['borderColor']     = $bordercolor_result;
                $blockData['backgroundColor'] = $bgcolor_result;
                $blockData['wasadd']          = 0;
                $blockData['deleted']         = 0;
                $blockData['type']            = $blockType;
                $blockData['subType']         = $blockType;
                if (strtolower($blockType) == "image") {
                    //$defaultsType         = config('editorConfig.object.image');
                    $defaultsType         = isset($this->editorConfig['object']) && isset($this->editorConfig['object']['image']) ? $this->editorConfig['object']['image'] : [];
                    $blockData['type']    = "image";
                    $blockData['subType'] = "image";
                }
                if (in_array(strtolower($blockType), array('text', 'textflow'))) {
                    //    $defaultsType             = config('editorConfig.object.text');
                    $defaultsType = isset($this->editorConfig['object']) && isset($this->editorConfig['object']['text']) ? $this->editorConfig['object']['text']
                        : [];;
                    $blockData['type']        = "text";
                    $blockData['subType']     = 'textflow';
                    $isFontStyle              = $this->getBlockProp($i, $j, 'fontstyle', array('propType' => 'name'));
                    $isUnderline              = $this->getBlockProp($i, $j, 'underline', array('propType' => 'boolean'));
                    $isCharSpacing            = $this->getBlockProp($i, $j, 'charspacing', array('propType' => 'number'));
                    $isWordSpacing            = $this->getBlockProp($i, $j, 'wordspacing', array('propType' => 'number'));
                    $textAlign                = 'left';
                    $verticalAlign            = 'middle';
                    $fontstyle                = $isFontStyle ? $isFontStyle : '';
                    $underline                = $isUnderline ? $isUnderline : '';
                    $charspacing              = $isCharSpacing ? $isCharSpacing : 0;
                    $wordspacing              = $isWordSpacing ? $isWordSpacing : 0;
                    $fillcolor_result         = $this->readTemplateColor($this->pdf, $this->doc, $i, $j, 'fillcolor');
                    $blockData['fontFamily']  = $this->pdf->pcos_get_string($this->doc, "pages[$i]/blocks[$j]/fontname");
                    $blockData['fontSize']    = $this->getBlockProp($i, $j, 'fontsize', array('propType' => 'number'));
                    $blockData['fillColor']   = $fillcolor_result;
                    $blockData['underline']   = $underline;
                    $blockData['bold']        = ($fontstyle == 'bold' || $fontstyle == 'bolditalic') ? 1 : 0;
                    $blockData['italic']      = ($fontstyle == 'italic' || $fontstyle == 'bolditalic') ? 1 : 0;
                    $blockData['charspacing'] = $charspacing;
                    $blockData['wordspacing'] = $wordspacing;
                    $position0                = '';
                    $position1                = '';
                    if (in_array(strtolower($blockType), array('text'))) {
                        // $defaultsSubType      = config('editorConfig.object.textline');
                        $defaultsSubType = isset($this->editorConfig['object']) && isset($this->editorConfig['object']['textline']) ? $this->editorConfig['object']['textline']
                            : [];;
                        //$defaultsSubType      = config('editorConfig.object.textline');
                        $blockData['subType'] = 'textline';
                        $isPositionArray      = $this->getBlockPropType($i, $j, 'position') == 'array' ? 1 : 0;
                        
                        if ($isPositionArray) {
                            $isPosition0 = $this->getBlockProp($i, $j, 'position[0]', array('propType' => 'number'));
                            $isPosition1 = $this->getBlockProp($i, $j, 'position[1]', array('propType' => 'number'));
                            $position0   = $isPosition0 ? $isPosition0 : '';
                            $position1   = $isPosition1 ? $isPosition1 : '';
                            
                            if ($position1 == '' && $position0 != '') {
                                $position1 = $position0;
                            }
                         //   $position1 = 'bottom';
                           
                            if ($position0 == '50') {
                                $textAlign = 'center';
                            }
                            if ($position0 == '100') {
                                $textAlign = 'right';
                            }
                            
                            if ($position1 == '50') {
                                $verticalAlign = 'middle';
                            }
                            if ($position1 == '100') {
                                $verticalAlign = 'top';
                            }
                            
                        }
                        if ($isTextflow) {
                            if ($this->pdf->pcos_get_string($this->doc, "type:pages[$i]/blocks[$j]/alignment") == 'name') {
                                $position0 = $this->pdf->pcos_get_string($this->doc, "pages[$i]/blocks[$j]/alignment");
                            }
                            if ($this->pdf->pcos_get_string($this->doc, "type:pages[$i]/blocks[$j]/verticalalign") == "name") {
                                $position1 = $this->pdf->pcos_get_string($this->doc, "pages[$i]/blocks[$j]/verticalalign");
                            } else {
                                $position1 = 'top';
                            }
                        }
                        
                    }
                    
                    
                    if (in_array(strtolower($blockType), array('text')) && $isTextflow) {
                        //$defaultsSubType      = config('editorConfig.object.textflow');
                        $defaultsSubType = isset($this->editorConfig['object']) && isset($this->editorConfig['object']['textflow']) ? $this->editorConfig['object']['textflow']
                            : [];;
                        $blockData['subType'] = "textflow";
                        $isLineheightN        = $this->getBlockProp($i, $j, 'leading', array('propType' => 'number'));
                        $isLineheightP        = $this->getBlockProp($i, $j, 'leading[0]', array('propType' => 'array'));
                        $isAlignment          = $this->getBlockProp($i, $j, 'alignment', array('propType' => 'name'));
                        $isVAlignment         = $this->getBlockProp($i, $j, 'verticalalign', array('propType' => 'name'));
                        $lineheightn          = $isLineheightN ? $isLineheightN : 0;
                        $lineheightp          = $isLineheightP ? $isLineheightP : 0;
                        $verticalAlign        = 'top';
                        if ($position0 == 'center') {
                            $textAlign = 'center';
                        }
                        if ($position0 == 'right') {
                            $textAlign = 'right';
                        }
                        if ($position0 == 'justify') {
                            $textAlign = 'justify';
                        }
                        if ($position1 == 'bottom') {
                            $verticalAlign = 'bottom';
                        }
                        if ($position1 == 'top') {
                            $verticalAlign = 'top';
                        }
                        if ($position1 == 'center') {
                            $verticalAlign = 'middle';
                        }
                        $blockData['lineheightn'] = $lineheightn;
                        $blockData['lineheightp'] = $lineheightp;
                    }
                    if (isset($blockData['template']) && $blockData['template']) {
                        $textAlign     = 'left';
                        $verticalAlign = 'top';
                    }
                    $blockData['textAlign'] = $textAlign;
                    $blockData['vAlign']    = $verticalAlign;
                }
                
                array_push($projectData['pages']['page_' . $i]['objectsIds'], $name);
                $general = array_merge($defaultsGeneral, $defaultsType, $defaultsSubType);
                //  $diffs   = FunctionsHelpers::getDiffs($blockData, $general, array());
                // $projectData['objects'][$name] = $diffs;
                $projectData['objects'][$name] = $blockData;
            }
        }
        return $projectData;
    }
    
    public function getBlockProp($page, $block, $name, $options = array('propType' => false))
    {
        $value = false;
        try {
            $query = "pages[$page]/blocks[$block]/$name";
            
            if (isset($options['propType']) && $options['propType']) {
                $propType = $this->getBlockPropType($page, $block, $name);
                
                if ($propType != $options['propType']) {
                    throw  new \Exception('Invalid property type');
                }
            }
            switch ($propType) {
                case 'number':
                    $value = $this->pdf->pcos_get_string($this->doc, $query);
                    break;
                case'string':
                    $value = $this->pdf->pcos_get_string($this->doc, $query);
                    break;
                case 'array':
                    $value = $this->pdf->pcos_get_number($this->doc, $query);
                    break;
                case'name':
                    $value = $this->pdf->pcos_get_string($this->doc, $query);
                    break;
                
            }
        } catch (\Exception $e) {
            return false;
        }
        return $value;
    }
    
    public function getPageProp($page, $name, $type = 'string', $options = array())
    {
        $value = false;
        try {
            $query = "pages[$page]/$name";
            
            switch ($type) {
                case 'number':
                    $value = $this->pdf->pcos_get_number($this->doc, $query);
                    break;
                case'string':
                    $value = $this->pdf->pcos_get_string($this->doc, $query);
                    break;
            }
        } catch (\Exception $e) {
            return false;
        }
        return $value;
    }
    
    public function getBlockPropType($page, $block, $name, $options = array())
    {
        $type = false;
        try {
            $type = $this->pdf->pcos_get_string($this->doc, "type:pages[$page]/blocks[$block]/$name");
        } catch (\Exception $e) {
            return false;
        }
        return $type;
    }
    
    public function getPagePropType($page, $name, $options = array())
    {
        $type = false;
        try {
            $type = $this->pdf->pcos_get_string($this->doc, "type:pages[$page]/$name");
            
        } catch (\Exception $e) {
            return false;
        }
        return $type;
    }
    
    public function ValidateBlockName($name)
    {
        if (strpos($name, '.') !== false) {
            return false;
        }
        return true;
    }
    
    private function readTemplateColor($pdf = false, $doc = false, $page = false, $block = false, $type = '')
    {
        $color = array(
            'colorspace'            => '',
            'color'                 => '',
            'separation_tint'       => 1,
            'separation_colorspace' => '',
            'separation_color'      => '',
        
        );
        try {
            if (!$pdf) {
                throw new \Exception (__('Invalid pdf'));
            }
            if (!$doc) {
                throw new \Exception (('Invalid doc'));
            }
            if (!in_array($type, array('fillcolor', 'backgroundcolor', 'bordercolor'))) {
                throw new \Exception (('Invalid color type'));
            }
            
            
            if ($pdf->pcos_get_string($doc, "type:pages[$page]/blocks[$block]/$type") == 'array') {
                $colorSpaceType = $pdf->pcos_get_string($doc, "type:pages[$page]/blocks[$block]/$type" . "[0]");
                if ($colorSpaceType == 'name') {
                    $color['colorspace'] = $pdf->pcos_get_string($doc, "pages[$page]/blocks[$block]/$type" . "[0]");
                }
                if ($colorSpaceType == 'array') {
                    $color['colorspace'] = $pdf->pcos_get_string($doc,
                        "pages[$page]/blocks[$block]/$type" . "[0][0]");
                }
                switch ($color['colorspace']) {
                    case 'DeviceGray':
                        $color['color'] = $pdf->pcos_get_string($doc, "pages[$page]/blocks[$block]/$type" . "[1]");
                        break;
                    case 'DeviceRGB':
                        for ($c = 0; $c < 3; $c++) {
                            $ce             = $pdf->pcos_get_string($doc,
                                "pages[$page]/blocks[$block]/$type" . "[1][$c]");
                            $color['color'] .= (($ce == '1.0' || $ce == '0.0') ? (int)$ce : $ce) . ' ';
                        }
                        break;
                    case 'DeviceCMYK':
                        for ($c = 0; $c < 4; $c++) {
                            $ce             = $pdf->pcos_get_string($doc,
                                "pages[$page]/blocks[$block]/$type" . "[1][$c]");
                            $color['color'] .= (($ce == '1.0' || $ce == '0.0') ? (int)$ce : $ce) . ' ';
                        }
                        break;
                    case 'Separation':
                        $color['color']           = $pdf->pcos_get_string($doc,
                            "pages[$page]/blocks[$block]/$type" . "[0][1]");
                        $color['separation_tint'] = $pdf->pcos_get_string($doc,
                            "pages[$page]/blocks[$block]/$type" . "[1]");
                        if ($pdf->pcos_get_string($doc,
                                "type:pages[$page]/blocks[$block]/$type" . "[2][0]") == 'name'
                        ) {
                            $color['separation_colorspace'] = $pdf->pcos_get_string($doc,
                                "pages[$page]/blocks[$block]/$type" . "[2][0]");
                        }
                        
                        switch ($color['separation_colorspace']) {
                            case 'DeviceGray':
                                $color['separation_color'] = $pdf->pcos_get_string($doc,
                                    "pages[$page]/blocks[$block]/$type" . "[2][1]");
                                break;
                            case 'DeviceRGB':
                                for ($c = 0; $c < 3; $c++) {
                                    $ce                        = $pdf->pcos_get_string($doc,
                                        "pages[$page]/blocks[$block]/$type" . "[2][1][$c]");
                                    $color['separation_color'] .= (($ce == '1.0' || $ce == '0.0') ? (int)$ce : $ce) . ' ';
                                }
                                break;
                            case 'DeviceCMYK':
                                for ($c = 0; $c < 4; $c++) {
                                    $ce                        = $pdf->pcos_get_string($doc,
                                        "pages[$page]/blocks[$block]/$type" . "[2][1][$c]");
                                    $color['separation_color'] .= (($ce == '1.0' || $ce == '0.0') ? (int)$ce : $ce) . ' ';
                                }
                                break;
                        }
                        
                        break;
                }
            }
            
        } catch (Exception $e) {
            $color = false;
        }
        
        return $color;
    }
    
    protected function getCustomOptions($value)
    {
        // $mapping  = config('editorConfig.customOptionsMapping');
        $mapping  = isset($this->editorConfig['customOptionsMapping']) ? $this->editorConfig['customOptionsMapping']
            : [];
        $defaults = array();

        if (isset($value['custom']) && count($value['custom']) > 0) {
            foreach ($value['custom'] as $customField) {
                if (array_key_exists($customField['key'], $mapping)) {
                    $defaults[$mapping[$customField['key']]]
                        = $customField['value'];
                }
            }
            return $defaults;
        }
    }
    
    
    private function validateCustomBlocks(
        $pdf,
        &$doc,
        $page,
        $block,
        $blockNr,
        &$projectData,
        $file = '',
        $blockName = ''
    ) {
        $allowedTypes = isset($this->custom_options) ? $this->custom_options : [];
        
        $checkPhpTypes = array(
            'FormatRule',
            'ValidationRule',
        );
        for ($k = 0; $k < $blockNr; $k++) {
            
            $key = preg_replace('/[-\s]+/', ' ',
                $pdf->pcos_get_string($doc, "pages[$page]/blocks[$block]/Custom[$k].key"));
            $key = ucwords($key);
            $key = trim(preg_replace('/[^a-z]+/i', '', $key));
            
            if (!in_array($key, $allowedTypes)) {
                if (!empty($file)) {
                    throw new \Exception(("Block $key, from pdf $file, is not allowed"));
                } else {
                    throw new \Exception(("Block $key is not allowed"));
                }
            }
            
            $value = $pdf->pcos_get_string($doc, "pages[$page]/blocks[$block]/Custom[$k].val");
            
            // try and validate php code for validation and format rules
            if (in_array($key, $checkPhpTypes)) {
                // $tmp = public_path() . '/personalization/tmp/parseMe.php';
                $tmp = ROOT_PATH . '/data/parse/parseMe.php';
                $put = file_put_contents($tmp, '<?php ' . preg_replace('/(<\?(=|php)?|\?>)+/i', '', $value));
                if ($put) {
                    
                    $ret = array();
                    exec('php -l ' . $tmp, $ret);
                    if (!empty($ret)) {
                        
                        if (count($ret) > 2) {
                            unlink($tmp);
                            throw new \Exception(('Parse error validating rule for ' . $key));
                        }
                    }
                    unlink($tmp);
                }
            }
            
            if ($key == 'Value') {
                $projectData["objects"][$blockName]['default'] = $value;
            }
            if ($key == 'Prefix') {
                $projectData["objects"][$blockName]['prefix'] = $value;
            }
            $projectData["objects"][$blockName]['custom'][] = array(
                'key'   => $key,
                'value' => $value,
            );
            
        }
    }
}
