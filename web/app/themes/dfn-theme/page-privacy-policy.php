<?php
/**
 * Template Name: Privacy Policy Page
 * Description: Template dedicato e stilizzato per la pagina Informativa sulla Privacy & Cookie Policy
 *
 * @package DFN_Theme
 */

if (! defined('ABSPATH')) {
    exit;
}

get_header();
?>

<main id="primary" class="site-main dfn-privacy-page-main" style="background-color: #f8fafc; min-height: 80vh; padding: 40px 16px 80px 16px;">
    <div class="dfn-privacy-wrapper" style="max-width: 900px; margin: 0 auto;">
        
        <!-- Hero Header -->
        <header class="dfn-privacy-hero" style="background: linear-gradient(135deg, #004b23 0%, #002b14 100%); color: #ffffff; padding: 40px 32px; border-radius: 20px; text-align: center; box-shadow: 0 10px 30px rgba(0,75,35,0.15); margin-bottom: 30px; position: relative; overflow: hidden;">
            <div style="font-size: 44px; margin-bottom: 10px;">🔒</div>
            <h1 style="font-size: 28px; font-weight: 800; color: #ffffff; margin: 0 0 10px 0; text-transform: uppercase; letter-spacing: 0.5px;">
                <?php the_title(); ?>
            </h1>
            <p style="font-size: 15px; color: #e2e8f0; margin: 0; max-width: 650px; margin: 0 auto; line-height: 1.5;">
                <?php esc_html_e('Informativa resa ai sensi dell\'art. 13 del Regolamento UE 2016/679 (GDPR) e della normativa italiana in materia di protezione dei dati personali.', 'dfn-theme'); ?>
            </p>
        </header>

        <!-- Main Content Card -->
        <article class="dfn-privacy-card" style="background: #ffffff; padding: 40px 48px; border-radius: 20px; border: 1px solid #e2e8f0; box-shadow: 0 4px 20px rgba(0,0,0,0.03); color: #334155; font-size: 16px; line-height: 1.7;">
            <?php
            while (have_posts()) :
                the_post();
                the_content();
            endwhile;
            ?>
        </article>

    </div>
</main>

<?php
get_footer();
