<?php

$searchpath = dirname(__FILE__,3)."/input";

$outfile = "";
$title = "Underlined Text";
$tf = 0;
$llx= 50; $lly=50; $urx=500; $ury=800;
$y = $ury;
$optlist =
    "fontname=NotoSerif-Regular fontsize=13 " .
    "fillcolor={gray 0} alignment=justify charref";

/* Text for the Textflow samples. Soft hyphens are marked with the
 * character reference "&shy;" (character references are enabled by the
 * charref option).
 */
$text=
    "<underline>Our paper planes<underline=false>" .
    " are the " .
    "<underline>ideal<underline=false> " .
    "way of passing the time. We offer " .
    "<underline=true>re&shy;volu&shy;tionary<underline=false> " .
    "new develop&shy;ments of the traditional common paper planes. If your " .
    "lesson, conference, or lecture turn out to be deadly boring, you " .
    "can have a wonderful time with our planes. All our models are " .
    "fol&shy;ded from one paper sheet. They are exclu&shy;sively folded " .
    "with&shy;out using any adhesive. Several models are equipped with " .
    "a folded landing gear enabling a " .
    "<underline>safe landing<underline=false> " .
    "on the intended loca&shy;tion provided that you " .
    "have aimed well. Other models are able to fly loops or cover long " .
    "distances. Let them start from a vista point in the mountains " .
    "and see where they touch the ground. ";

/* Text which uses matchboxes for underline decoration
*/

$coloredtext =
    "<matchbox={nodrawleft drawbottom nodrawright nodrawtop borderwidth=1.5 strokecolor=red offsetbottom=-2}>" .
    "Our paper planes" .
    "<matchbox={end}> " .
    "are the " .
    "<matchbox {nodrawleft drawbottom nodrawright nodrawtop borderwidth=1 doubleoffset=2 strokecolor=green offsetbottom=-1}>" .
    "ideal" .
    "<matchbox={end}> " .
    "way of passing the time. We offer " .
    "<matchbox={nodrawleft drawbottom nodrawright nodrawtop borderwidth=1 strokecolor=gray offsetbottom=-2 dasharray {3 2}}>" .
    "re&shy;volu&shy;tionary" .
    "<matchbox={end}> " .
    "new develop&shy;ments of the traditional " .
    "common paper planes. If your lesson, conference, or lecture turn " .
    "out to be deadly boring, you can have a wonderful time with our " .
    "planes. All our models are fol&shy;ded from one paper sheet. They " .
    "are exclu&shy;sively folded with&shy;out using any adhesive. " .
    "Several models are equipped with a folded landing gear enabling a " .
    "<matchbox={borderwidth=1.5 openrect strokecolor={spotname {PANTONE 123 U} 1} offsetbottom=-1}>" .
    "safe landing" .
    "<matchbox={end}> " .
    "on the intended loca&shy;tion provided that you " .
    "have aimed well. Other models are able to fly loops or cover long " .
    "distances. Let them start from a vista point in the mountains and ".
    "see where they touch the ground. ";

try {
    $p = new pdflib();

    $p->set_option("searchpath={" . $searchpath . "}");

    /* This means we must check return values of load_font() etc. */
    $p->set_option("errorpolicy=return");

    if ($p->begin_document($outfile, "") == 0)
        throw new Exception("Error: " . $p->get_errmsg());

    $p->set_info("Creator", "PDFlib Cookbook");
    $p->set_info("Title", $title );

    $font = $p->load_font("NotoSerif-Regular", "unicode", "");
    if ($font == 0)
        throw new Exception("Error: " . $p->get_errmsg());

    $p->begin_page_ext(0, 0, "width=a4.width height=a4.height");

    $p->setfont($font, 18);

    /* ******************************************************** */
    $p->fit_textline("Textline with default underline settings:",
        $llx, $y, "fillcolor=red");

    $p->fit_textline("Our paper planes are the ideal way of passing the time.",
        $llx, $y-=25, "underline");


    /* ******************************************************** */
    $p->fit_textline("Textline with custom underline width and position:",
        $llx, $y-=50, "fillcolor=red");

    $p->fit_textline("Our paper planes are the ideal way of passing the time.",
        $llx, $y-=25, "underline underlinewidth=3 underlineposition=-40%");


    /* ******************************************************** */
    $p->fit_textline("Textflow with default underline settings:",
        $llx, $y-=65, "fillcolor=red");

    $tf = $p->create_textflow($text, $optlist . " leading=120%");
    if ($tf == 0)
        throw new Exception("Error: " . $p->get_errmsg());

    $result = $p->fit_textflow($tf, $llx, $lly-200, $urx, $y-=10, "");
    if ($result != "_stop") {
        /* Check for further action */
    }
    $p->delete_textflow($tf);

    /* ******************************************************** */
    $p->fit_textline("Textflow with custom underline width and position:",
        $llx, $y-=190, "fillcolor=red");

    $tf = $p->create_textflow($text, $optlist . " leading=140% " .
                                     "underlinewidth=1.5 underlineposition=-30%");
    if ($tf == 0)
        throw new Exception("Error: " . $p->get_errmsg());

    $result = $p->fit_textflow($tf, $llx, $lly, $urx, $y-=10, "");
    if ($result !="_stop") {
        /* Check for further action */
    }
    $p->delete_textflow($tf);


    /* ******************************************************** */
    $p->fit_textline("Textflow with custom underlines via matchboxes:",
        $llx, $y-=220, "fillcolor=red");

    $tf = $p->create_textflow($coloredtext, $optlist  . " leading=120% ");
    if ($tf == 0)
        throw new Exception("Error: " . $p->get_errmsg());

    $result = $p->fit_textflow($tf, $llx, $lly, $urx, $y-=10, "");
    if ($result != "_stop") {
        /* Check for further action */
    }
    $p->delete_textflow($tf);

    $p->end_page_ext("");

    $p->end_document("");

    $buf = $p->get_buffer();
    $len = strlen($buf);

    header("Content-type: application/pdf");
    header("Content-Length: $len");
    header("Content-Disposition: inline; filename=underlined_text.pdf");
    print $buf;

} catch (PDFlibException $e) {
    echo("PDFlib exception occurred:\n".
         "[" . $e->get_errnum() . "] " . $e->get_apiname() .
         ": " . $e->get_errmsg() . "\n");
    exit(1);
} catch (Throwable $e) {
    echo("PHP exception occurred: " . $e->getMessage() . "\n");
    exit(1);
}

$p = 0;
