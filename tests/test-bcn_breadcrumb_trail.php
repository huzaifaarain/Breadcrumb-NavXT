<?php
/**
 * This file contains tests for the bcn_breadcrumb class
 *
 * @group bcn_breadcrumb_trail
 * @group bcn_core
 */
if(class_exists('bcn_breadcrumb_trail'))
{
	class bcn_breadcrumb_trail_DUT extends bcn_breadcrumb_trail {
		function __construct() {
			parent::__construct();
		}
		//Super evil caller function to get around our private and protected methods in the parent class
		function call($function, $args = array()) {
			return call_user_func_array(array($this, $function), $args);
		}
 	}
}
class BreadcrumbTrailTest extends WP_UnitTestCase {
	public $breadcrumb_trail;
	function setUp() {
		parent::setUp();
		$this->breadcrumb_trail = new bcn_breadcrumb_trail_DUT();
		//Register some types to use for various tests
		register_post_type('czar', array(
			'label' => 'Czars',
			'public' => true,
			'hierarchical' => false,
			'has_archive' => true,
			'publicaly_queryable' => true,
			'taxonomies' => array('post_tag', 'category')
			)
		);
		register_post_type('bureaucrat', array(
			'label' => 'Bureaucrats',
			'public' => true,
			'hierarchical' => true,
			'has_archive' => true,
			'publicaly_queryable' => true
			)
		);
		register_taxonomy('ring', 'czar', array(
			'lable' => 'Rings',
			'public' => true,
			'hierarchical' => false,
			)
		);
		register_taxonomy('party', 'czar', array(
			'lable' => 'Parties',
			'public' => true,
			'hierarchical' => true,
			)
		);
		register_taxonomy('job_title', 'bureaucrat', array(
			'lable' => 'Job Title',
			'public' => true,
			'hierarchical' => true,
			)
		);
	}
	public function tearDown() {
		parent::tearDown();
	}
	/**
	 * Tests for the bcn_post_terms filter
	 */
	function test_bcn_post_terms() {
		//Create our terms and post
		$tids = $this->factory->tag->create_many(10);
		$pid = $this->factory->post->create(array('post_title' => 'Test Post'));
		//Assign the terms to the post
		wp_set_object_terms($pid, $tids, 'post_tag');
		//Now call post_terms
		$this->breadcrumb_trail->call('post_terms', array($pid, 'post_tag'));
		//Ensure we have only one breadcrumb
		$this->assertCount(1, $this->breadcrumb_trail->breadcrumbs);
		//Now disect this breadcrumb
		$title_exploded = explode(', ', $this->breadcrumb_trail->breadcrumbs[0]->get_title());
		//Ensure we have only 10 sub breadcrumbs
		$this->assertCount(10, $title_exploded);
		//Reset the breadcrumb trail
		$this->breadcrumb_trail->breadcrumbs = array();
		//Now register our filter
		add_filter('bcn_post_terms', 
			function($terms, $taxonomy, $id) {
				return array_slice($terms, 2, 5);
		}, 3, 10);
		//Call post_terms again
		$this->breadcrumb_trail->call('post_terms', array($pid, 'post_tag'));
		//Ensure we have only one breadcrumb
		$this->assertCount(1, $this->breadcrumb_trail->breadcrumbs);
		//Now disect this breadcrumb
		$title_exploded = explode(', ', $this->breadcrumb_trail->breadcrumbs[0]->get_title());
		//Ensure we have only 5 sub breadcrumbs
		$this->assertCount(5, $title_exploded);
	}
	/**
	 * Tests for the bcn_add_post_type_arg filter
	 */
	function test_bcn_add_post_type_arg() {		
		//Call maybe_add_post_type_arg
		$url1 = $this->breadcrumb_trail->call('maybe_add_post_type_arg', array('http://foo.bar/car', 'czar', 'category'));
		//Ensure we added a post type arg
		$this->assertSame('http://foo.bar/car?post_type=czar', $url1);
		//Now register our filter
		add_filter('bcn_add_post_type_arg', 
			function($add_query_arg, $type, $taxonomy) {
				return false;
		}, 3, 10);
		//Call maybe_add_post_type_arg again
		$url2 = $this->breadcrumb_trail->call('maybe_add_post_type_arg', array('http://foo.bar/car', 'czar', 'ring'));
		//Ensure we didn't add a post type arg
		$this->assertSame('http://foo.bar/car', $url2);
	}
	/**
	 * Tests for the bcn_pick_post_term filter
	 */
	function test_bcn_pick_post_term() {
		global $tids;
		//Create our terms and post
		$tids = $this->factory->category->create_many(10);
		$pid = $this->factory->post->create(array('post_title' => 'Test Post'));
		//Make some of the terms be in a hierarchy
		wp_update_term($tids[7], 'category', array('parent' => $tids[8]));
		wp_update_term($tids[8], 'category', array('parent' => $tids[6]));
		//Assign the terms to the post
		wp_set_object_terms($pid, $tids, 'category');
		//Call post_hierarchy
		$this->breadcrumb_trail->call('post_hierarchy', array($pid, 'post'));
		//Inspect the resulting breadcrumb
		//Ensure we have 3 breadcrumbs
		$this->assertCount(3, $this->breadcrumb_trail->breadcrumbs);
		//Should be term 7, 8, 6
		$this->assertSame(get_term($tids[7], 'category')->name, $this->breadcrumb_trail->breadcrumbs[0]->get_title());
		$this->assertSame(get_term($tids[8], 'category')->name, $this->breadcrumb_trail->breadcrumbs[1]->get_title());
		$this->assertSame(get_term($tids[6], 'category')->name, $this->breadcrumb_trail->breadcrumbs[2]->get_title());
		//Now, let's add a filter that selects a middle id
		add_filter('bcn_pick_post_term', 
			function($term, $id, $type) {
				global $tids;
				$terms = get_the_terms($id, 'category');
				foreach($terms as $sterm)
				{
					if($sterm->term_id == $tids[3])
					{
						return $sterm;	
					}
				}
				return $term;
		}, 3, 10);
		//Reset the breadcrumb trail
		$this->breadcrumb_trail->breadcrumbs = array();
		//Call post_hierarchy
		$this->breadcrumb_trail->call('post_hierarchy', array($pid, 'post'));
		//Inspect the resulting breadcrumb
		//Ensure we have 1 breadcrumb
		$this->assertCount(1, $this->breadcrumb_trail->breadcrumbs);
		//Should only be term 3
		$this->assertSame(get_term($tids[3], 'category')->name, $this->breadcrumb_trail->breadcrumbs[0]->get_title());
	}
	/**
	 * Tests for the pick_post_term function
	 */
	function test_pick_post_term() {
		global $tids;
		//Create our terms and post
		$tids = $this->factory->category->create_many(10);
		$pid = $this->factory->post->create(array('post_title' => 'Test Post'));
		//Make some of the terms be in a hierarchy
		wp_update_term($tids[7], 'category', array('parent' => $tids[8]));
		wp_update_term($tids[8], 'category', array('parent' => $tids[6]));
		wp_update_term($tids[9], 'category', array('parent' => $tids[8]));
		wp_update_term($tids[5], 'category', array('parent' => $tids[7]));
		//Setup a second hierarchy
		wp_update_term($tids[2], 'category', array('parent' => $tids[1]));
		wp_update_term($tids[3], 'category', array('parent' => $tids[2]));
		//Assign the terms to the post
		wp_set_object_terms($pid, $tids, 'category');
		//Call post_hierarchy, should have gotten the deepest in the first returned hierarchy
		//However, we do not know the order of term return, so just check for any valid response (any deepest child)
		$this->assertThat(
			$this->breadcrumb_trail->call('pick_post_term', array($pid, 'post', 'category'))->name,
			$this->logicalOr(
				$this->equalTo(get_term($tids[3], 'category')->name),
				$this->equalTo(get_term($tids[5], 'category')->name),
				$this->equalTo(get_term($tids[9], 'category')->name)
			)
		);
	}
	function test_query_var_to_taxonomy() {
		//Setup some taxonomies
		register_taxonomy('custom_tax0', 'post', array('query_var' => 'custom_tax_0'));
		register_taxonomy('custom_tax1', 'post', array('query_var' => 'custom_tax_1'));
		register_taxonomy('custom_tax1', 'post', array('query_var' => 'custom_tax_2'));
		//Check matching of an existant taxonomy
		$this->assertSame('custom_tax0', $this->breadcrumb_trail->call('query_var_to_taxonomy', array('custom_tax_0')));
		//Check return false of non-existant taxonomy
		$this->assertFalse($this->breadcrumb_trail->call('query_var_to_taxonomy', array('custom_tax_326375')));
	}
	function test_determine_taxonomy() {
		$this->set_permalink_structure('/%category%/%postname%/');
		//Create the custom taxonomy
		register_taxonomy('wptests_tax2', 'post', array(
			'hierarchical' => true,
			'rewrite' => array(
				'slug' => 'foo',
				'hierarchical true'
				)));
		//Create some terms
		$tids = $this->factory->category->create_many(10);
		$ttid1 = $this->factory()->term->create(array(
			'taxonomy' => 'wptests_tax2',
			'slug' => 'ctxterm1'));
		$ttid2 = $this->factory()->term->create(array(
			'taxonomy' => 'wptests_tax2',
			'slug' => 'ctxterm2',
			'parent' => $ttid1));
		//Create a test post
		$pid = $this->factory->post->create(array('post_title' => 'Test Post'));
		//Make some of the terms be in a hierarchy
		wp_update_term($tids[7], 'category', array('parent' => $tids[8]));
		wp_update_term($tids[8], 'category', array('parent' => $tids[6]));
		wp_update_term($tids[9], 'category', array('parent' => $tids[8]));
		wp_update_term($tids[5], 'category', array('parent' => $tids[7]));
		//Assign the terms to the post
		wp_set_object_terms($pid, array($tids[5]), 'category');
		wp_set_object_terms($pid, $ttid2, 'wptests_tax2');
		flush_rewrite_rules();
		//"Go to" our post
		$this->go_to(get_permalink($pid));
		//Check no referer
		$this->assertFalse($this->breadcrumb_trail->call('determine_taxonomy'));
		//Let the custom taxonomy be our referer
		$_SERVER['HTTP_REFERER'] = get_term_link($ttid2);
		//Check matching of an existant taxonomy
		$this->assertSame('wptests_tax2', $this->breadcrumb_trail->call('determine_taxonomy'));
	}
	function test_do_root()
	{
		//Create some pages
		$paid = $this->factory->post->create_many(10, array('post_type' => 'page'));
		//Setup some relationships between the posts
		wp_update_post(array('ID' => $paid[0], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[1], 'post_parent' => $paid[2]));
		wp_update_post(array('ID' => $paid[2], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[6], 'post_parent' => $paid[5]));
		wp_update_post(array('ID' => $paid[5], 'post_parent' => $paid[0]));
		//Set page '3' as the home page
		update_option('page_on_front', $paid[3]);
		//Set page '6' as the root for posts
		update_option('page_for_posts', $paid[6]);
		$this->set_permalink_structure('/%category%/%postname%/');
		//Create some terms
		$tids = $this->factory->category->create_many(10);
		//Create a test post
		$pid = $this->factory->post->create(array('post_title' => 'Test Post'));
		//Make some of the terms be in a hierarchy
		wp_update_term($tids[7], 'category', array('parent' => $tids[8]));
		wp_update_term($tids[8], 'category', array('parent' => $tids[6]));
		wp_update_term($tids[9], 'category', array('parent' => $tids[8]));
		wp_update_term($tids[5], 'category', array('parent' => $tids[7]));
		//Assign the terms to the post
		wp_set_object_terms($pid, array($tids[5]), 'category');
		//"Go to" our post
		$this->go_to(get_permalink($pid));
		$this->breadcrumb_trail->call('do_root');
		//Ensure we have 3 breadcrumbs
		$this->assertCount(3, $this->breadcrumb_trail->breadcrumbs);
		//Check to ensure we got the breadcrumbs we wanted
		$this->assertEquals($paid[0], $this->breadcrumb_trail->breadcrumbs[2]->get_id());
		$this->assertSame(get_the_title($paid[0]), $this->breadcrumb_trail->breadcrumbs[2]->get_title());
		$this->assertEquals($paid[5], $this->breadcrumb_trail->breadcrumbs[1]->get_id());
		$this->assertSame(get_the_title($paid[5]), $this->breadcrumb_trail->breadcrumbs[1]->get_title());
		$this->assertEquals($paid[6], $this->breadcrumb_trail->breadcrumbs[0]->get_id());
		$this->assertSame(get_the_title($paid[6]), $this->breadcrumb_trail->breadcrumbs[0]->get_title());
	}
	function test_do_root_page()
	{
		//Create some pages
		$paid = $this->factory->post->create_many(10, array('post_type' => 'page'));
		//Setup some relationships between the posts
		wp_update_post(array('ID' => $paid[0], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[1], 'post_parent' => $paid[2]));
		wp_update_post(array('ID' => $paid[2], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[6], 'post_parent' => $paid[5]));
		wp_update_post(array('ID' => $paid[5], 'post_parent' => $paid[0]));
		//Set page '3' as the home page
		update_option('page_on_front', $paid[9]);
		//Set page '6' as the root for posts
		update_option('page_for_posts', $paid[6]);
		//"Go to" our post
		$this->go_to(get_permalink($paid[1]));
		$this->breadcrumb_trail->call('do_root');
		//Ensure we have 0 breadcrumbs, root should not do anything for pages (we get to all but the home in post_parents)
		$this->assertCount(0, $this->breadcrumb_trail->breadcrumbs);
	}
	function test_do_root_blog_home()
	{
		//Create some pages
		$paid = $this->factory->post->create_many(10, array('post_type' => 'page'));
		//Setup some relationships between the posts
		wp_update_post(array('ID' => $paid[0], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[1], 'post_parent' => $paid[2]));
		wp_update_post(array('ID' => $paid[2], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[6], 'post_parent' => $paid[5]));
		wp_update_post(array('ID' => $paid[5], 'post_parent' => $paid[0]));
		//Set page '3' as the home page
		update_option('page_on_front', $paid[9]);
		//Set page '6' as the root for posts
		update_option('page_for_posts', $paid[6]);
		//"Go to" our post
		$this->go_to(get_home_url());
		$this->breadcrumb_trail->call('do_root');
		//Ensure we have 4 breadcrumbs
		$this->assertCount(4, $this->breadcrumb_trail->breadcrumbs);
		//Check to ensure we got the breadcrumbs we wanted
		$this->assertEquals($paid[3], $this->breadcrumb_trail->breadcrumbs[3]->get_id());
		$this->assertSame(get_the_title($paid[3]), $this->breadcrumb_trail->breadcrumbs[3]->get_title());
		$this->assertEquals($paid[0], $this->breadcrumb_trail->breadcrumbs[2]->get_id());
		$this->assertSame(get_the_title($paid[0]), $this->breadcrumb_trail->breadcrumbs[2]->get_title());
		$this->assertEquals($paid[5], $this->breadcrumb_trail->breadcrumbs[1]->get_id());
		$this->assertSame(get_the_title($paid[5]), $this->breadcrumb_trail->breadcrumbs[1]->get_title());
		$this->assertEquals($paid[6], $this->breadcrumb_trail->breadcrumbs[0]->get_id());
		$this->assertSame(get_the_title($paid[6]), $this->breadcrumb_trail->breadcrumbs[0]->get_title());
	}
	function test_do_root_no_blog()
	{
		//Create some pages
		$paid = $this->factory->post->create_many(10, array('post_type' => 'page'));
		//Setup some relationships between the posts
		wp_update_post(array('ID' => $paid[0], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[1], 'post_parent' => $paid[2]));
		wp_update_post(array('ID' => $paid[2], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[6], 'post_parent' => $paid[5]));
		wp_update_post(array('ID' => $paid[5], 'post_parent' => $paid[0]));
		//Set page '3' as the home page
		update_option('page_on_front', $paid[3]);
		//Set page '6' as the root for posts
		update_option('page_for_posts', $paid[6]);
		$this->set_permalink_structure('/%category%/%postname%/');
		//Create some terms
		$tids = $this->factory->category->create_many(10);
		//Create a test post
		$pid = $this->factory->post->create(array('post_title' => 'Test Post'));
		//Make some of the terms be in a hierarchy
		wp_update_term($tids[7], 'category', array('parent' => $tids[8]));
		wp_update_term($tids[8], 'category', array('parent' => $tids[6]));
		wp_update_term($tids[9], 'category', array('parent' => $tids[8]));
		wp_update_term($tids[5], 'category', array('parent' => $tids[7]));
		//Assign the terms to the post
		wp_set_object_terms($pid, array($tids[5]), 'category');
		//"Go to" our post
		$this->go_to(get_permalink($pid));
		//We don't want the blog breadcrumb
		$this->breadcrumb_trail->opt['bblog_display'] = false;
		$this->breadcrumb_trail->call('do_root');
		//Ensure we have 0 breadcrumbs
		$this->assertCount(0, $this->breadcrumb_trail->breadcrumbs);
	}
	function test_do_root_cpt()
	{
		//Create some pages
		$paid = $this->factory->post->create_many(10, array('post_type' => 'page'));
		//Setup some relationships between the posts
		wp_update_post(array('ID' => $paid[0], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[1], 'post_parent' => $paid[2]));
		wp_update_post(array('ID' => $paid[2], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[6], 'post_parent' => $paid[5]));
		wp_update_post(array('ID' => $paid[5], 'post_parent' => $paid[0]));
		//Set page '3' as the home page
		update_option('page_on_front', $paid[3]);
		//Set page '6' as the root for posts
		update_option('page_for_posts', $paid[6]);
		register_post_type('bcn_testa', array('public' => true, 'rewrite' => array('slug' => 'bcn_testa')));
		flush_rewrite_rules();
		//Have to setup some CPT specific settings
		$this->breadcrumb_trail->opt['apost_bcn_testa_root'] = $paid[1];
		$this->breadcrumb_trail->opt['Hpost_bcn_testa_template'] = bcn_breadcrumb::get_default_template();
		$this->breadcrumb_trail->opt['Hpost_bcn_testa_template_no_anchor'] = bcn_breadcrumb::default_template_no_anchor;
		$this->set_permalink_structure('/%category%/%postname%/');
		//Create a test post
		$pid = $this->factory->post->create(array('post_title' => 'Test Post', 'post_type' => 'bcn_testa'));
		//"Go to" our post
		$this->go_to(get_permalink($pid));
		$this->breadcrumb_trail->call('do_root');
		//Ensure we have 2 breadcrumbs
		$this->assertCount(2, $this->breadcrumb_trail->breadcrumbs);
		//Check to ensure we got the breadcrumbs we wanted
		$this->assertEquals($paid[2], $this->breadcrumb_trail->breadcrumbs[1]->get_id());
		$this->assertSame(get_the_title($paid[2]), $this->breadcrumb_trail->breadcrumbs[1]->get_title());
		$this->assertEquals($paid[1], $this->breadcrumb_trail->breadcrumbs[0]->get_id());
		$this->assertSame(get_the_title($paid[1]), $this->breadcrumb_trail->breadcrumbs[0]->get_title());
	}
	/**
	 * Test for when the CPT root setting is a non-integer see #148
	 */
	function test_do_root_cpt_null_root()
	{
		//Create some pages
		$paid = $this->factory->post->create_many(10, array('post_type' => 'page'));
		//Setup some relationships between the posts
		wp_update_post(array('ID' => $paid[0], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[1], 'post_parent' => $paid[2]));
		wp_update_post(array('ID' => $paid[2], 'post_parent' => $paid[3]));
		wp_update_post(array('ID' => $paid[6], 'post_parent' => $paid[5]));
		wp_update_post(array('ID' => $paid[5], 'post_parent' => $paid[0]));
		//Set page '3' as the home page
		update_option('page_on_front', $paid[3]);
		//Set page '6' as the root for posts
		update_option('page_for_posts', $paid[6]);
		register_post_type('bcn_testa', array('public' => true, 'rewrite' => array('slug' => 'bcn_testa')));
		flush_rewrite_rules();
		//Have to setup some CPT specific settings
		$this->breadcrumb_trail->opt['apost_bcn_testa_root'] = NULL;
		$this->breadcrumb_trail->opt['Hpost_bcn_testa_template'] = bcn_breadcrumb::get_default_template();
		$this->breadcrumb_trail->opt['Hpost_bcn_testa_template_no_anchor'] = bcn_breadcrumb::default_template_no_anchor;
		$this->set_permalink_structure('/%category%/%postname%/');
		//Create a test post
		$pid = $this->factory->post->create(array('post_title' => 'Test Post', 'post_type' => 'bcn_testa'));
		//"Go to" our post
		$this->go_to(get_permalink($pid));
		$this->breadcrumb_trail->call('do_root');
		//Ensure we have 0 breadcrumbs (no root)
		$this->assertCount(0, $this->breadcrumb_trail->breadcrumbs);
	}
}