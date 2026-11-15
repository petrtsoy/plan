<?php

namespace App\Support;

use App\Models\Asset;

/**
 * Значок единицы на плане: по названию (стул, холодильник, кровать), иначе значок или название
 * категории, иначе значок или код слоя. Сами картинки – в public/js/floor.js (ICONS).
 */
class AssetIcons
{
    /** Порядок важен: более частное раньше ("кресло-кровать" – кровать, а не кресло). */
    private const RULES = [
        '/(прикроватн|пациент)\S* монитор|монитор\S* (пациент|прикроват)/u' => 'heart',
        '/кресло-кров|(^|[^а-я])кроват|кушетк/u' => 'bed',
        '/коляск/u' => 'wheelchair',
        '/стол\S* с тумб/u' => 'table',
        '/стойк\S* (для )?инфуз|инфузомат|капельниц/u' => 'drip',
        '/ширм/u' => 'screen',
        '/вешалк/u' => 'hanger',
        '/холодильн|морозильн/u' => 'fridge',
        '/микроволн/u' => 'microwave',
        '/гарнитур руковод/u' => 'table',
        '/гарнитур|мойк|раковин/u' => 'kitchen',
        '/операционн\S* стол|стол операц/u' => 'optable',
        '/процедурн\S* столик|тележк/u' => 'trolley',
        '/диван|софа|sofa/u' => 'sofa',
        '/кресло|сидени/u' => 'armchair',
        '/стул|табурет|седло/u' => 'chair',
        '/шкаф\S* вытяжн|бокс|ламинар/u' => 'hood',
        '/сейф/u' => 'safe',
        '/тумб|комод/u' => 'drawers',
        '/шкаф|гардероб/u' => 'cabinet',
        '/стеллаж|полк|обувниц/u' => 'shelf',
        '/стол|ресепшен/u' => 'table',
        '/ноутбук|компьютер|моноблок|системн\S* блок/u' => 'laptop',
        '/монитор/u' => 'monitor',
        '/принтер|мфу/u' => 'printer',
        '/сканер/u' => 'scanner',
        '/розетк/u' => 'socket',
        '/коммутатор|роутер|маршрутизатор|точк\S* доступа|сервер|сетев/u' => 'network',
        '/камер/u' => 'camera',
        '/огнетуш/u' => 'extinguisher',
        '/датчик\S* (дым|пожар|тепл)|извещател|сигнализац/u' => 'smoke',
        '/пожарн\S* кран|гидрант/u' => 'hydrant',
        '/эвакуац|выход/u' => 'exit',
        '/ивл|вентиляци\S* легк|дыхательн/u' => 'lungs',
        '/щит|ибп|стабилизатор/u' => 'bolt',
        '/кислород|газ|компрессор/u' => 'gas',
        '/кондиционер|сплит|вентил|вытяжк/u' => 'snow',
        '/термометр|гигрометр|датчик\S* темп/u' => 'thermo',
        '/узи|ультразв/u' => 'ultrasound',
        '/рентген|томограф|флюорограф|\bкт\b/u' => 'xray',
        '/кардиограф|экг|энцефалограф|функциональн/u' => 'ecg',
        '/стерилиз|автоклав|дезинф|облучател/u' => 'sterilizer',
        '/микроскоп/u' => 'microscope',
        '/центрифуг/u' => 'centrifuge',
        '/анализатор/u' => 'analyzer',
        '/дозатор|весы|пипет/u' => 'scale',
        '/амплификатор|пцр|термостат|миксер|шейкер/u' => 'flask',
        '/ламп|светильник/u' => 'lamp',
        '/паллет|поддон/u' => 'pallet',
        '/доск/u' => 'board',
        '/зеркал/u' => 'mirror',
        '/ростомер/u' => 'height',
    ];

    private const LAYERS = [
        'medical' => 'cross', 'lab' => 'flask', 'it' => 'monitor',
        'furniture' => 'cabinet', 'fire' => 'extinguisher', 'utilities' => 'bolt',
    ];

    public static function guess(?string $text): ?string
    {
        $text = mb_strtolower((string) $text);
        // Латиница вместо похожей кириллицы рядом с русскими буквами ("Cтол").
        $text = preg_replace_callback('/[aceopxk](?=[а-я])|(?<=[а-я])[aceopxk]/u',
            fn ($m) => strtr($m[0], ['a' => 'а', 'c' => 'с', 'e' => 'е', 'o' => 'о', 'p' => 'р', 'x' => 'х', 'k' => 'к']), $text);
        foreach (self::RULES as $re => $icon) {
            if ($text !== '' && preg_match($re, $text)) {
                return $icon;
            }
        }

        return null;
    }

    public static function for(Asset $a): string
    {
        $c = $a->category;

        return self::guess($a->name ?: $a->model?->title)
            ?? ($c->icon ?: null)
            ?? self::guess($c->name)
            ?? ($c->layer->icon ?: null)
            ?? self::LAYERS[$c->layer->code]
            ?? 'dot';
    }
}
