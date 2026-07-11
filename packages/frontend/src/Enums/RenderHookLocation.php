<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum RenderHookLocation: string
{
    // Existing content hooks (unchanged)
    case BeforeTitle = 'beforeTitle';
    case AfterTitle = 'afterTitle';
    case Footer = 'footer';
    case BeforeResult = 'beforeResult';
    case AfterResult = 'afterResult';
    case ArticleMeta = 'articleMeta';
    case BeforeContent = 'beforeContent';
    case AfterContent = 'afterContent';
    case MainContent = 'mainContent';

    // New HTML document hooks (Phase 4 frontend neutralization depends on these)
    case HeadOpen = 'headOpen';
    case HeadClose = 'headClose';
    case BodyStart = 'bodyStart';
    case HeaderBefore = 'headerBefore';
    case HeaderAfter = 'headerAfter';
    case FooterBefore = 'footerBefore';
    case FooterAfter = 'footerAfter';
    case BodyEnd = 'bodyEnd';
}
