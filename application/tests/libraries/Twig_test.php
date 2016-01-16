<?php

class Twig_test extends TestCase
{
	public static function setUpBeforeClass()
	{
		parent::setUpBeforeClass();
		$CI =& get_instance();
		$CI->load->library('twig');
		$CI->load->helper('url');
	}

	public function setUp()
	{
		$CI =& get_instance();
		
		$loader = new Twig_Loader_Array([
			'base_url' => '{{ base_url(\'"><s>abc</s><a name="test\') }}',
			'site_url' => '{{ site_url(\'"><s>abc</s><a name="test\') }}',
		]);
		$CI->twig->setLoader($loader);
		$CI->twig->createTwig();
		
		$this->obj = $CI->twig;
		$this->twig = $CI->twig->getTwig();
	}

	public function test_safe_anchor()
	{
		$actual = $this->obj->safe_anchor('news/local/123', 'My News', array('title' => 'The best news!'));
		$expected = '<a href="http://localhost/CodeIgniter/news/local/123" title="The best news!">My News</a>';
		$this->assertEquals($expected, $actual);
		
		$actual = $this->obj->safe_anchor('news/local/123', '<s>abc</s>', array('<s>name</s>' => '<s>val</s>'));
		$expected = '<a href="http://localhost/CodeIgniter/news/local/123" &lt;s&gt;name&lt;/s&gt;="&lt;s&gt;val&lt;/s&gt;">&lt;s&gt;abc&lt;/s&gt;</a>';
		$this->assertEquals($expected, $actual);
	}

	public function test_base_url()
	{
		$actual = $this->twig->render('base_url');
		$expected = 'http://localhost/CodeIgniter/&quot;&gt;&lt;s&gt;abc&lt;/s&gt;&lt;a name=&quot;test';
		$this->assertEquals($expected, $actual);
	}

	public function test_site_url()
	{
		$actual = $this->twig->render('site_url');
		$expected = 'http://localhost/CodeIgniter/&quot;&gt;&lt;s&gt;abc&lt;/s&gt;&lt;a name=&quot;test';
		$this->assertEquals($expected, $actual);
	}
}
