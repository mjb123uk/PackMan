<?php
/**
 * Template Packager
 *
 * Copyright 2010 by Shaun McCormick <shaun@collabpad.com>
 *
 * This file is part of Template Packager.
 *
 * Template Packager is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option) any
 * later version.
 *
 * Template Packager is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * Template Packager; if not, write to the Free Software Foundation, Inc., 59
 * Temple Place, Suite 330, Boston, MA 02111-1307 USA
 *
 * @package templatepackager
 */
/**
 * Builds the package and exports it.
 *
 * @package templatepackager
 * @subpackage processors
 */
/* if downloading the file last exported */
if (!empty($_REQUEST['download'])) {
    $file = $_REQUEST['download'];
    sleep(.5); /* to make sure not to go too fast */
    $d = $modx->getOption('core_path').'packages/'.$_REQUEST['download'];
    $f = $d.'.transport.zip';

    if (!is_file($f)) return '';

    $o = file_get_contents($f);
    $bn = basename($file);

    header("Content-Type: application/force-download");
    header("Content-Disposition: attachment; filename=\"{$bn}.transport.zip\"");

    /* remove package files now that we are through */
    @unlink($f);
    $modx->cacheManager->deleteTree($d.'/',true,false,array());

    return $o;
}

/* verify form */
if (empty($_POST['category'])) $modx->error->addField('category',$modx->lexicon('templatepackager.category_err_ns'));
if (empty($_POST['version'])) $modx->error->addField('version',$modx->lexicon('templatepackager.version_err_nf'));
if (empty($_POST['release'])) $modx->error->addField('release',$modx->lexicon('templatepackager.release_err_nf'));

/* if any errors, return and dont proceed */
if ($modx->error->hasError()) {
    return $modx->error->failure();
}

/* get version, release, files */
$version = $_POST['version'];
$release = $_POST['release'];

/* format package name */
$name_lower = strtolower($_POST['category']);
$name_lower = str_replace(array(' ','-','.','*','!','@','#','$','%','^','&','_'),'',$name_lower);

/* define file paths and string replacements */
$directories = array();
$cachePath = $modx->getOption('core_path').'cache/';
$pathLookups = array(
    'sources' => array(
        '{base_path}',
        '{core_path}',
        '{assets_path}',
    ),
    'targets' => array(
        $modx->getOption('base_path',null,MODX_BASE_PATH),
        $modx->getOption('core_path',null,MODX_CORE_PATH),
        $modx->getOption('assets_path',null,MODX_ASSETS_PATH),
    )
);

$modx->loadClass('transport.modPackageBuilder','',false, true);
$builder = new modPackageBuilder($modx);
$builder->createPackage($name_lower,$version,$release);
$builder->registerNamespace($name_lower,false,true,'{assets_path}templates/'.$name_lower.'/');

/* create category */
$category= $modx->newObject('modCategory');
$category->set('id',1);
$category->set('category',$_POST['category']);

/* add Chunks */
$chunkList = $modx->fromJSON($_POST['chunks']);
if (!empty($chunkList)) {
    $chunks = array();
    foreach ($chunkList as $chunkData) {
        if (empty($chunkData['id'])) continue;
        $chunk = $modx->getObject('modChunk',$chunkData['id']);
        if (empty($chunk)) continue;

        $chunks[] = $chunk;
    }
    if (empty($chunks)) {
        return $modx->error->failure('Error packaging chunks!');
    }
    $category->addMany($chunks,'Chunks');
    $modx->log(modX::LOG_LEVEL_INFO,'Packaged in '.count($chunks).' chunks...');
}

/* add snippets */
$snippetList = $modx->fromJSON($_POST['snippets']);
if (!empty($snippetList)) {
    $snippets = array();
    foreach ($snippetList as $snippetData) {
        if (empty($snippetData['id'])) continue;
        $snippet = $modx->getObject('modSnippet',$snippetData['id']);
        if (empty($snippet)) continue;

        $snippets[] = $snippet;

        /* package in assets_path if it exists */
        if (!empty($snippetData['assets_path'])) {
            $files = str_replace($pathLookups['sources'],$pathLookups['targets'],$snippetData['assets_path']);
            $l = strlen($files);
            if (substr($files,$l-1,$l) != '/') $files .= '/';
            /* verify files exist */
            if (file_exists($files) && is_dir($files)) {
                $directories[] = array(
                    'source' => $files,
                    'target' => "return MODX_ASSETS_PATH . 'components/';",
                );
            }
        }
        /* package in core_path if it exists */
        if (!empty($snippetData['core_path'])) {
            $files = str_replace($pathLookups['sources'],$pathLookups['targets'],$snippetData['core_path']);
            $l = strlen($files);
            if (substr($files,$l-1,$l) != '/') $files .= '/';
            /* verify files exist */
            if (file_exists($files) && is_dir($files)) {
                $directories[] = array(
                    'source' => $files,
                    'target' => "return MODX_CORE_PATH . 'components/';",
                );
            }
        }
    }
    if (empty($snippets)) {
        return $modx->error->failure('Error packaging Snippets!');
    }
    $category->addMany($snippets,'Snippets');
    $modx->log(modX::LOG_LEVEL_INFO,'Packaged in '.count($snippets).' Snippets...');
}

/* add Templates */
$tvs = array();
$tvMap = array();
$templateList = $modx->fromJSON($_POST['templates']);
if (!empty($templateList)) {
    $templates = array();
    foreach ($templateList as $templateData) {
        if (empty($templateData['id'])) continue;
        $template = $modx->getObject('modTemplate',$templateData['id']);
        if (empty($template)) continue;

        $templates[] = $template;
        /* add in directory for Template */
        if (!empty($templateData['directory'])) {
            $files = str_replace($pathLookups['sources'],$pathLookups['targets'],$templateData['directory']);
            $l = strlen($files);
            if (substr($files,$l-1,$l) != '/') $files .= '/';
            /* verify files exist */
            if (file_exists($files) && is_dir($files)) {
                $directories[] = array(
                    'source' => $files,
                    'target' => "return MODX_ASSETS_PATH . 'templates/';",
                );
            }
        }

        /* collect TVs assigned to Template */
        $c = $modx->newQuery('modTemplateVar');
        $c->innerJoin('modTemplateVarTemplate','TemplateVarTemplates');
        $c->where(array(
            'TemplateVarTemplates.templateid' => $template->get('id'),
        ));
        $tvList = $modx->getCollection('modTemplateVar',$c);
        foreach ($tvList as $tv) {
            $tvs[] = $tv;
            /* add tvs and template to map for the tvt resolver */
            if (!isset($tvMap[$tv->get('name')])) {
                $tvMap[$tv->get('name')] = array();
            }
            array_push($tvMap[$tv->get('name')],$template->get('templatename'));
            $tvMap[$tv->get('name')] = array_unique($tvMap[$tv->get('name')]);
        }
    }
    if (empty($templates)) {
        return $modx->error->failure('Error packaging Templates!');
    }
    $category->addMany($templates,'Templates');
    $modx->log(modX::LOG_LEVEL_INFO,'Packaged in '.count($templates).' Templates...');
}

/* add in TVs */
$category->addMany($tvs);

/* package in category vehicle */
$attr = array(
    xPDOTransport::UNIQUE_KEY => 'category',
    xPDOTransport::PRESERVE_KEYS => false,
    xPDOTransport::UPDATE_OBJECT => true,
    xPDOTransport::RELATED_OBJECTS => true,
    xPDOTransport::RELATED_OBJECT_ATTRIBUTES => array (
        'Chunks' => array (
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => true,
            xPDOTransport::UNIQUE_KEY => 'name',
        ),
        'TemplateVars' => array (
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => true,
            xPDOTransport::UNIQUE_KEY => 'name',
        ),
        'Templates' => array (
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => true,
            xPDOTransport::UNIQUE_KEY => 'templatename',
        ),
        'Snippets' => array (
            xPDOTransport::PRESERVE_KEYS => false,
            xPDOTransport::UPDATE_OBJECT => true,
            xPDOTransport::UNIQUE_KEY => 'name',
        ),
    ),
);
$vehicle = $builder->createVehicle($category,$attr);
/* add directories to category vehicle */
if (!empty($directories)) {
    foreach ($directories as $directory) {
        $vehicle->resolve('file',$directory);
    }
    $modx->log(modX::LOG_LEVEL_INFO,'Added '.count($directories).' directories to category...');
}

/* create dynamic TemplateVarTemplate resolver */
if (!empty($tvMap)) {
    $tvp = var_export($tvMap,true);
    $resolverCachePath = $cachePath.'templatepackager/resolve.tvt.php';
    $resolver = file_get_contents($modx->tp->config['includesPath'].'resolve.tvt.php');
    $resolver = str_replace(array('{tvs}'),array($tvp),$resolver);

    $modx->cacheManager->writeFile($resolverCachePath,$resolver);
    $vehicle->resolve('php',array(
        'source' => $resolverCachePath,
    ));
}

/* add category vehicle to build */
$builder->putVehicle($vehicle);

/* add in packages */
$packageList = $modx->fromJSON($_POST['packages']);
if (!empty($packageList)) {
    $packageDir = $modx->getOption('core_path',null,MODX_CORE_PATH).'packages/';
    $spAttr = array('vehicle_class' => 'xPDOTransportVehicle');
    $spReplaces = array(
        '{version}',
        '{version_major}',
        '{name}',
    );

    foreach ($packageList as $packageData) {
        $file = $packageDir.$packageData['signature'].'.transport.zip';
        if (!file_exists($file)) continue;

        /* create package as subpackage */
        $vehicle = $builder->createVehicle(array(
            'source' => $file,
            'target' => "return MODX_CORE_PATH . 'packages/';",
        ),$spAttr);

        /* get signature values */
        $sig = explode('-',$packageData['signature']);
        $vsig = explode('.',$sig[1]);
        
        /* create custom package validator to resolve if the package on the client server is newer than this version */
        $cacheKey = 'templatepackager/validators/'.$packageData['signature'].'.php';
        $validator = file_get_contents($modx->tp->config['includesPath'].'validate.subpackage.php');
        $validator = str_replace($spReplaces,array(
            $sig[1].(!empty($sig[2]) ? '-'.$sig[2] : ''),
            $vsig[0],
            $sig[0],
        ),$validator);
        $modx->cacheManager->writeFile($cachePath.$cacheKey,$validator);

        /* add validator to vehicle */
        $vehicle->validate('php',array(
            'source' => $cachePath.$cacheKey,
        ));

        /* add subpackage to build */
        $builder->putVehicle($vehicle);
    }
}

/* now pack in the license file, readme and setup options */
$packageAttributes = array();
if (isset($_FILES['license']) && !empty($_FILES['license']) && $_FILES['license']['error'] == UPLOAD_ERR_OK) {
    $packageAttributes['license'] = file_get_contents($_FILES['license']['tmp_name']);
}
if (isset($_FILES['readme']) && !empty($_FILES['readme']) && $_FILES['readme']['error'] == UPLOAD_ERR_OK) {
    $packageAttributes['readme'] = file_get_contents($_FILES['readme']['tmp_name']);
}
if (!empty($packageAttributes)) $builder->setPackageAttributes($packageAttributes);


/* zip up the package */
$builder->pack();

/* remove any cached files */
$modx->cacheManager->deleteTree($cachePath.'templatepackager/',array(
    'deleteTop' => true,
    'skipDirs' => false,
    'extensions' => array('.php'),
));

/* output name to browser */
$signature = $name_lower.'-'.$version.'-'.$release;
return $modx->error->success($signature);