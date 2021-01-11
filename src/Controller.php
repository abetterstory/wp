<?php

namespace ABetter\WP;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Closure;
use Corcel\Model\Post;
use Corcel\Model\Option;

class Controller extends BaseController {

	public function handle() {
		// Prepare
		$this->args = func_get_args();
		$this->uri = $_SERVER['REQUEST_URI'] ?? '';
		$this->path = preg_replace('/\/\/+/','/','/'.trim($this->args[0]??'','/').'/');
		$this->theme = env('WP_THEME') ?: '';
		// Locations
		if ($this->theme) {
			$this->prependLocation(base_path().'/resources/views/'.$this->theme);
			view()->addLocation(base_path().'/vendor/abetter/wp/views/'.$this->theme);
		}
		view()->addLocation(base_path().'/vendor/abetter/wp/views/default');
		$this->location = Config::get('view.paths');
		// Service
		if (preg_match('/^\/robots/',$this->uri)) return $this->serviceRobots();
		if (preg_match('/^\/sitemap/',$this->uri)) return $this->serviceSitemap();
		// Post
		if (!$this->post = $this->getPreview($this->path,$this->uri)) {
			if (!$this->post = $this->getPost($this->path)) {
				if (!$this->post = $this->getError()) {
					return abort(404);
				}
			}
		}
		// Template
		$this->template = $this->getPostTemplate($this->post);
		$this->suggestions = $this->getPostTemplateSuggestions($this->post,$this->template);
		// View
		foreach ($this->suggestions AS $suggestion) {
			if (view()->exists($suggestion)) {
				$this->view = $suggestion;
				break;
			}
		}
		// Response
		if (!empty($this->view)) {
			return view($suggestion)->with([
				'post' => $this->post,
				'template' => $suggestion,
				'error' => $this->error ?? NULL,
				'expire' => $this->post->meta->settings_expire ?? NULL,
				'redirect' => $this->post->meta->settings_redirect ?? NULL,
			]);
		}
		// Fail
		if (in_array(strtolower(env('APP_ENV')),['production','stage'])) return abort(404);
		return "No template found in views.";
    }

	// ---

	public function serviceRobots() {
		$this->service = 'robots';
		return response()->view('robots')->header('Content-Type','text/html');
	}

	public function serviceSitemap() {
		$this->service = 'sitemap';
		return response()->view('sitemap')->header('Content-Type','text/xml');
	}

	// ---

	public function getPreview($path,$uri) {
		if ($path !== '/' || !preg_match('/preview/',$uri)) return NULL;
		$this->preview = (preg_match('/(page_id|p)(\=|\/)([0-9]+)/',$uri,$match)) ? (int) $match[3] : NULL;
		return Post::where('id', $this->preview)->first();
	}

	public function getPost($path) {
		$this->guid = 'route:'.$path;
		return Post::where('guid', $this->guid)->first();
	}

	public function getError() {
		$this->error = 404;
		return Post::where('post_name', 'like', "{$this->error}%")->first();
	}

	// ---

	public function getPostTemplate($post) {
		$template = strtok($post->meta->_wp_page_template ?? 'default', '.');
		if ($template == 'default') {
			$template = $post->post_type;
			if (($id = Option::get('page_on_front')) && $post->ID == $id) $template = 'front';
			if (($id = Option::get('page_for_posts')) && $post->ID == $id) $template = 'posts';
		}
		return $template;
	}

	public function getPostTemplateSuggestions($post,$template) {
		$suggestions = [];
		if (empty($this->post->ID)) return $suggestions;
		if ($post->post_type == 'post') $suggestions[] = 'page';
		$suggestions[] = $post->post_type;
		$suggestions[] = $post->post_type.'--'.$post->post_name;
		if ($template != $post->post_type) $suggestions[] = $template;
		if (!empty($this->error)) {
			if ($template != 'error') $suggestions[] = 'error';
			$suggestions[] = (string) $this->error;
		}
		return array_reverse($suggestions);
	}

	// ---

	public function prependLocation($path) {
        Config::set('view.paths', array_merge([$path], Config::get('view.paths')));
        View::setFinder(app()['view.finder']);
    }

}