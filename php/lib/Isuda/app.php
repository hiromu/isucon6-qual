<?php
namespace Isuda\Web;

use Slim\Http\Request;
use Slim\Http\Response;
use PDO;
use PDOWrapper;

function config($key) {
    static $conf;
    if ($conf === null) {
        $conf = [
            'dsn'           => $_ENV['ISUDA_DSN']         ?? 'dbi:mysql:db=isuda',
            'db_user'       => $_ENV['ISUDA_DB_USER']     ?? 'isucon',
            'db_password'   => $_ENV['ISUDA_DB_PASSWORD'] ?? 'isucon',
            'isupam_origin' => $_ENV['ISUPAM_ORIGIN']     ?? 'http://localhost:5050',
        ];
    }

    if (empty($conf[$key])) {
        exit("config value of $key undefined");
    }
    return $conf[$key];
}

$container = new class extends \Slim\Container {
    public $dbh;
    public function __construct() {
        parent::__construct();

        $this->dbh = new PDOWrapper(new PDO(
            $_ENV['ISUDA_DSN'],
            $_ENV['ISUDA_DB_USER'] ?? 'isucon',
            $_ENV['ISUDA_DB_PASSWORD'] ?? 'isucon',
            [ PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4" ]
        ));
    }

    public function make_trie() {
	$keywords = $this->dbh->select_all(
            'SELECT keyword FROM entry'
        );
	$trie = trie_filter_new();	
	for ($i = 0; $i < count($keywords); $i++)
		trie_filter_store($trie, $keywords[$i]['keyword']);
	trie_filter_save($trie, __DIR__ . '/trie.save.orig');
	copy(__DIR__ . '/trie.save.orig', __DIR__ . '/trie.save');
    }

    public function add_trie($keyword) {
	$trie = trie_filter_load(__DIR__ . '/trie.save');
	if (!$trie)
		return;
	trie_filter_store($trie, $keyword);
	trie_filter_save($trie, __DIR__ . '/trie.save');
    }

    public function load_trie() {
        $trie = trie_filter_load(__DIR__ . '/trie.save');
	if (!$trie)
		$trie = trie_filter_load(__DIR__ . '/trie.save.orig');
	return $trie;
	/*
	$keywords = $this->dbh->select_all(
            'SELECT keyword FROM entry'
        );
	$trie = trie_filter_new();	
	for ($i = 0; $i < count($keywords); $i++)
		trie_filter_store($trie, $keywords[$i]['keyword']);
	return $trie;
	*/
    }

    public function htmlify($trie, $content) {
        if (!isset($content)) {
            return '';
        }

	$matches = trie_filter_search_all($trie, $content);
	uasort($matches, function ($a, $b) {
		if ($a[0] < $b[0])
			return -1;
		else if ($a[0] > $b[0])
			return 1;
		else if ($a[1] < $b[1])
			return 1;
		else if ($a[1] > $b[1])
			return -1;
		return 0;
	});

	$ignore = array();
	$last = -1; $lastac = -1;
	foreach ($matches as $key => $value) {
		if ($last != -1) {
			if ($matches[$last][0] == $value[0]) {
				$ignore[] = $key;
			} else if ($matches[$lastac][0] + $matches[$lastac][1] > $value[0]) {
				$ignore[] = $key;
			} else {
				$lastac = $key;
			}
		} else {
			$lastac = $key;
		}
		$last = $key;
	}

	$offset = 0;
	foreach ($matches as $key => $value) {
		if (in_array($key, $ignore))
			continue;
		$kw = substr($content, $matches[$key][0] + $offset, $matches[$key][1]);
		$link = sprintf('<a href="%s">%s</a>', '/keyword/' . rawurlencode($kw), html_escape($kw));
		$content = substr($content, 0, $matches[$key][0] + $offset) . $link . substr($content, $matches[$key][0] + $matches[$key][1] + $offset);
		$offset += strlen($link) - $matches[$key][1];
	}

        return nl2br($content, true);
    }
};
$container['view'] = function ($container) {
    $view = new \Slim\Views\Twig($_ENV['PHP_TEMPLATE_PATH'], []);
    $view->addExtension(new \Slim\Views\TwigExtension(
        $container['router'],
        $container['request']->getUri()
    ));
    return $view;
};
$container['stash'] = new \Pimple\Container;
$app = new \Slim\App($container);

$mw = [];
// compatible filter 'set_name'
$mw['set_name'] = function ($req, $c, $next) {
    $user_id = $_SESSION['user_id'] ?? null;
    $user_name = $_SESSION['user_name'] ?? null;
    if (isset($user_id)) {
        $this->get('stash')['user_id'] = $user_id;
        $this->get('stash')['user_name'] = $user_name;
        if (!isset($this->get('stash')['user_name'])) {
            return $c->withStatus(403);
        }
    }
    return $next($req, $c);
};

$mw['authenticate'] = function ($req, $c, $next) {
    if (!isset($this->get('stash')['user_id'])) {
        return $c->withStatus(403);
    }
    return $next($req, $c);
};

$app->get('/initialize', function (Request $req, Response $c) {
    $this->dbh->query(
        'DELETE FROM entry WHERE id > 7101'
    );
    $this->dbh->query('TRUNCATE star');
    $this->make_trie();
    return render_json($c, [
        'result' => 'ok',
    ]);
});

$app->get('/', function (Request $req, Response $c) {
    $PER_PAGE = 10;
    $page = $req->getQueryParams()['page'] ?? 1;

    $offset = $PER_PAGE * ($page-1);
    $entries = $this->dbh->select_all(
        'SELECT e.*, x.stars FROM entry as e '.
        'LEFT JOIN ('.
            'SELECT s.keyword, GROUP_CONCAT(s.user_name) AS stars '.
            'FROM star AS s '.
            'GROUP BY s.keyword'.
	') AS x '.
	'ON x.keyword = e.keyword '.
	'WHERE e.id IN (SELECT * FROM ('.
	    'SELECT id FROM entry ORDER BY updated_at DESC '.
	    "LIMIT $PER_PAGE OFFSET $offset".
	') AS t)'
	/*
        'ORDER BY updated_at DESC '.
        "LIMIT $PER_PAGE ".
        "OFFSET $offset"
	*/
    );

    $trie = $this->load_trie();
    foreach ($entries as &$entry) {
        $entry['html']  = $this->htmlify($trie, $entry['description']);
        if ($entry['stars'] === NULL) {
            $entry['stars'] = [];
        } else {
            $entry['stars'] = explode(',', $entry['stars']);
        }
    }
    unset($entry);

    $total_entries = $this->dbh->select_one(
        'SELECT COUNT(*) FROM entry'
    );
    $last_page = ceil($total_entries / $PER_PAGE);
    $pages = range(max(1, $page-5), min($last_page, $page+5));

    $this->view->render($c, 'index.twig', [ 'entries' => $entries, 'page' => $page, 'last_page' => $last_page, 'pages' => $pages, 'stash' => $this->get('stash') ]);
})->add($mw['set_name'])->setName('/');

$app->get('/robots.txt', function (Request $req, Response $c) {
    return $c->withStatus(404);
});

$app->post('/keyword', function (Request $req, Response $c) {
    $keyword = $req->getParsedBody()['keyword'];
    if (!isset($keyword)) {
        return $c->withStatus(400)->write("'keyword' required");
    }
    $user_id = $this->get('stash')['user_id'];
    $description = $req->getParsedBody()['description'];

    if (is_spam_contents($keyword . $description, $keyword)) {
        return $c->withStatus(400)->write('SPAM!');
    }
    $this->dbh->query(
        'INSERT INTO entry (author_id, keyword, description, created_at, updated_at)'
        .' VALUES (?, ?, ?, NOW(), NOW())'
        .' ON DUPLICATE KEY UPDATE'
        .' author_id = ?, keyword = ?, description = ?, updated_at = NOW()'
    , $user_id, $keyword, $description, $user_id, $keyword, $description);

    $this->add_trie($keyword);

    return $c->withRedirect('/');
})->add($mw['authenticate'])->add($mw['set_name']);

$app->get('/register', function (Request $req, Response $c) {
    return $this->view->render($c, 'authenticate.twig', [
        'action' => 'register', 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name'])->setName('/register');

$app->post('/register', function (Request $req, Response $c) {
    $name = $req->getParsedBody()['name'];
    $pw   = $req->getParsedBody()['password'];
    if ($name === '' || $pw === '') {
        return $c->withStatus(400);
    }
    $user_id = register($this->dbh, $name, $pw);

    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_name'] = $name;
    return $c->withRedirect('/');
});

function register($dbh, $user, $pass) {
    $salt = random_string('....................');
    $dbh->query(
        'INSERT INTO user (name, salt, password, created_at)'
        .' VALUES (?, ?, ?, NOW())'
    , $user, $salt, sha1($salt . $pass));

    return $dbh->last_insert_id();
}

$app->get('/login', function (Request $req, Response $c) {
    return $this->view->render($c, 'authenticate.twig', [
        'action' => 'login', 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name'])->setName('/login');

$app->post('/login', function (Request $req, Response $c) {
    $name = $req->getParsedBody()['name'];
    $row = $this->dbh->select_row(
        'SELECT * FROM user'
        . ' WHERE name = ?'
    , $name);
    if (!$row || $row['password'] !== sha1($row['salt'].$req->getParsedBody()['password'])) {
        return $c->withStatus(403);
    }

    $_SESSION['user_id'] = $row['id'];
    $_SESSION['user_name'] = $name;
    return $c->withRedirect('/');
});

$app->get('/logout', function (Request $req, Response $c) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-60, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    return $c->withRedirect('/');
});

$app->get('/keyword/{keyword}', function (Request $req, Response $c) {
    $keyword = $req->getAttribute('keyword');
    if ($keyword === null) return $c->withStatus(400);

    $entry = $this->dbh->select_row(
        'SELECT e.*, x.stars FROM entry as e '.
        'LEFT JOIN ('.
            'SELECT s.keyword, GROUP_CONCAT(s.user_name) AS stars '.
            'FROM star AS s '.
            'GROUP BY s.keyword'.
	') AS x '.
	'ON x.keyword = e.keyword '.
        'WHERE e.keyword = ?'
    , $keyword);
    if (empty($entry)) return $c->withStatus(404);

    $entry['html'] = $this->htmlify($this->load_trie(), $entry['description']);
    if ($entry['stars'] === NULL) {
        $entry['stars'] = [];
    } else {
        $entry['stars'] = explode(',', $entry['stars']);
    }

    return $this->view->render($c, 'keyword.twig', [
        'entry' => $entry, 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name']);

$app->post('/keyword/{keyword}', function (Request $req, Response $c) {
    $keyword = $req->getParsedBody()['keyword'];
    if ($keyword === null) return $c->withStatus(400);
    $delete = $req->getParsedBody()['delete'];
    if ($delete === null) return $c->withStatus(400);

    $entry = $this->dbh->select_row(
        'SELECT * FROM entry'
        .' WHERE keyword = ?'
    , $keyword);
    if (empty($entry)) return $c->withStatus(404);

    $this->dbh->query('DELETE FROM entry WHERE keyword = ?', $keyword);
    return $c->withRedirect('/');
})->add('authenticate')->add($mw['set_name']);

$app->get('/stars', function (Request $req, Response $c) {
    $stars = $this->dbh->select_all(
        'SELECT * FROM star WHERE keyword = ?'
    , $req->getParams()['keyword']);

    return render_json($c, [
        'stars' => $stars,
    ]);
});

$app->post('/stars', function (Request $req, Response $c) {
    $keyword = $req->getParams()['keyword'];

    $entry = $this->dbh->select_row(
        'SELECT * FROM entry'
        .' WHERE keyword = ?'
    , $keyword);
    if (empty($entry)) return $c->withStatus(404);

    $this->dbh->query(
        'INSERT INTO star (keyword, user_name, created_at) VALUES (?, ?, NOW())',
        $keyword,
        $req->getParams()['user']
    );
    return render_json($c, [
        'result' => 'ok',
    ]);
});

function is_spam_contents($content, $keyword) {
    foreach(file(__DIR__ . '/spams.txt', FILE_IGNORE_NEW_LINES) as $spam_key) {
        if ($spam_key == "[OK] $keyword") return false;
        if ($spam_key == "[NG] $keyword") return true;
    }
    return false;

    $ua = new \GuzzleHttp\Client;
    $res = $ua->request('POST', config('isupam_origin'), [
        'form_params' => ['content' => $content]
    ])->getBody();
    $data = json_decode($res, true);
    if ($data['valid']) {
        file_put_contents(__DIR__ . '/spams.txt', "[OK] $keyword\n", FILE_APPEND);
        return false;
    } else {
        file_put_contents(__DIR__ . '/spams.txt', "[NG] $keyword\n", FILE_APPEND);
        return true;
    }
}

$app->run();
