<?php

namespace Sleepy\Controllers;

class PropelBaseController extends BaseController implements RestInterface {
	/* Simple REST controller using Propel's object interface. */
	
	static function getOne($params) {
		$query_class = self::query_class();
		$query = new $query_class;
		$model = $query->findPK($params['id']);

		if($model) {
			render($model);
		} else {
			render(null, 404);
		}
	}

	static function getMany($params) {
		$query_class = self::query_class();
		$query = new $query_class;
		$collection = $query->find();
		render($collection);
	}

	static function delete($params) {
		$query_class = self::query_class();
		$query = new $query_class;
		$model = $query->findPK($params['id']);

		if($model) {
			render($model);
			$model->delete();
		} else {
			render(null, 404);
		}
	}

	/* Child classes must implement create() and update(). */
	static function create($params) { throw new \Sleepy\NotImplementedException(); }
	static function update($params) { throw new \Sleepy\NotImplementedException(); }

	private static function model_class() {
		/*
		 *   Called from:
		 *       \Sleepy\Controllers\PostController
		 *
		 *   Returns:
		 *       \Post
		*/

		return "\\" . str_replace('Controller', '', end(explode("\\", get_called_class())));
	}

	private static function query_class() {
		return self::model_class() . "Query";
	}
}

?>