<?php

namespace RefactoringGuru\Command\RealWorld;

/**
 * EN: Command Design Pattern
 *
 * Intent: Encapsulate a request as an object, thereby letting you parameterize
 * clients with different requests (e.g. queue or log requests) and support
 * undoable operations.
 *
 * Example: In this example, the Command pattern is used to queue web scraping
 * calls to the IMDB website and execute them one by one. The queue itself is
 * kept in a database which helps to preserve commands between script launches.
 *
 * RU: Паттерн Команда
 *
 * Назначение: Инкапсулирует запрос как объект, позволяя тем самым параметризовать
 * клиентов с различными запросами (например, запросами очереди или логирования) и  
 * поддерживать отмену операций.
 *
 * Пример: В этом примере паттерн Команда применяется для очереди вызовов веб-скрейпинга
 * на веб-сайте IMDB и выполнения их один за другим. Сама очередь хранится в базе данных,
 * которая помогает сохранять команды между запусками сценариев.
 */

/**
 * EN:
 * The Command interface declares the execution method as well as several
 * methods to get a command's metadata.
 *
 * RU:
 * Интерфейс Команды объявляет метод выполнения, а также несколько методов
 * получения метаданных команды.
 */
interface Command
{
    public function execute();

    public function getId();

    public function getStatus();
}

/**
 * EN:
 * The base web scraping Command defines the basic downloading infrastructure,
 * common to all concrete web scraping commands.
 *
 * RU:
 * Базовая Команда веб-скрейпинга устанавливает базовую инфраструктуру загрузки,
 * общую для всех конкретных команд веб-скрейпинга.
 */
abstract class WebScrapingCommand implements Command
{
    public $id;

    public $status = 0;

    /**
     * EN:
     * @var string URL for scraping.
     *
     * RU:
     * @var string URL для скрейпинга.
     */
    public $url;

    protected $rawContent;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getURL()
    {
        return $this->url;
    }

    /**
     * EN:
     * Since the execution methods for all web scraping commands are very
     * similar, we can provide a default implementation and let subclasses
     * override them if needed.
     *
     * Psst! An observant reader may spot another behavioral pattern in action
     * here.
     *
     * RU:
     * Поскольку исполняющие методы для всех команд веб-скрейпинга очень похожи,
     * мы можем предоставить реализацию по умолчанию и позволить подклассам
     * переопределить их при необходимости.
     *
     * Шш! Наблюдательный читатель может обнаружить здесь другой поведенческий 
     * паттерн в действии. 
     */
    public function execute()
    {
        $html = $this->download();
        $this->parse($html);
        $this->complete();
    }

    public function download()
    {
        $html = file_get_contents($this->getURL());
        print("WebScrapingCommand: Downloaded {$this->url}\n");

        return $html;
    }

    abstract public function parse($html);

    public function complete()
    {
        $this->status = 1;
        Queue::get()->completeCommand($this);
    }
}

/**
 * EN:
 * The Concrete Command for scraping the list of movie genres.
 *
 * RU:
 * Конкретная Команда для извлечения списка жанров фильма.
 */
class IMDBGenresScrapingCommand extends WebScrapingCommand
{
    public function __construct()
    {
        $this->url = "https://www.imdb.com/feature/genre/";
    }

    /**
     * EN:
     * Extract all genres and their search URLs from the page:
     * https://www.imdb.com/feature/genre/
     *
     * RU:
     * Извлечение всех жанров и их поисковых URL со страницы:
     * https://www.imdb.com/feature/genre/
     */
    public function parse($html)
    {
        preg_match_all("|href=\"(https://www.imdb.com/search/title\?genres=.*?)\"|", $html, $matches);
        print("IMDBGenresScrapingCommand: Discovered ".count($matches[1])." genres.\n");

        foreach ($matches[1] as $genre) {
            Queue::get()->add(new IMDBGenrePageScrapingCommand($genre));
        }
    }
}

/**
 * EN:
 * The Concrete Command for scraping the list of movies in a specific genre.
 *
 * RU:
 * Конкретная Команда для извлечения списка фильмов определённого жанра.
 */
class IMDBGenrePageScrapingCommand extends WebScrapingCommand
{
    private $page;

    public function __construct($url, $page = 1)
    {
        parent::__construct($url);
        $this->page = $page;
    }

    public function getURL()
    {
        return $this->url.'?page='.$this->page;
    }

    /**
     * EN:
     * Extract all movies from a page like this:
     * https://www.imdb.com/search/title?genres=sci-fi&explore=title_type,genres
     *
     * RU:
     * Извлечение всех фильмов со страницы вроде этой:
     * https://www.imdb.com/search/title?genres=sci-fi&explore=title_type,genres
     */
    public function parse($html)
    {
        preg_match_all("|href=\"(/title/.*?/)\?ref_=adv_li_tt\"|", $html, $matches);
        print("IMDBGenrePageScrapingCommand: Discovered ".count($matches[1])." movies.\n");

        foreach ($matches[1] as $moviePath) {
            $url = "https://www.imdb.com".$moviePath;
            Queue::get()->add(new IMDBMovieScrapingCommand($url));
        }

        // EN: Parse the next page URL.
        //
        // RU: Обработка URL следующей страницы.
        if (preg_match("|Next &#187;</a>|", $html)) {
            Queue::get()->add(new IMDBGenrePageScrapingCommand($this->url, $this->page + 1));
        }
    }
}

/**
 * EN:
 * The Concrete Command for scraping the movie details.
 *
 * RU:
 * Конкретная Команда для извлечения подробных сведений о фильме.
 */
class IMDBMovieScrapingCommand extends WebScrapingCommand
{
    /**
     * EN:
     * Get the movie info from a page like this:
     * https://www.imdb.com/title/tt4154756/
     *
     * RU:
     * Получить информацию о фильме с подобной страницы:
     * https://www.imdb.com/title/tt4154756/
     */
    public function parse($html)
    {
        if (preg_match("|<h1 itemprop=\"name\" class=\"\">(.*?)</h1>|", $html, $matches)) {
            $title = $matches[1];
        }
        print("IMDBMovieScrapingCommand: Parsed movie $title.\n");
    }
}

/**
 * EN:
 * The Queue class acts as an Invoker. It stacks the command objects and
 * executes them one by one. If the script execution is suddenly terminated, the
 * queue and all its commands can easily be restored, and you won't need to
 * repeat all of the executed commands.
 *
 * Note that this is a very primitive implementation of the command queue, which
 * stores commands in a local SQLite database. There are dozens of robust queue
 * solution available for use in real apps.
 *
 * RU:
 * Класс Очередь действует как Отправитель. Он складывает в стек объекты команд
 * и выполняет их поочерёдно. Если выполнение скрипта внезапно завершается,
 * очередь и все её команды могут быть легко восстановлены, и вам не нужно будет
 * повторять все выполненные команды.
 *
 * Обратите внимание, что это очень примитивная реализация очереди команд, 
 * которая хранит команды в локальной базе данных SQLite. Существуют десятки 
 * надёжных решений очереди, доступных для использования в реальных приложениях.
 */
class Queue
{
    private $db;

    public function __construct()
    {
        $this->db = new \SQLite3(__DIR__ . '/commands.sqlite',
            SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

        $this->db->query('CREATE TABLE IF NOT EXISTS "commands" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "command" TEXT,
            "status" INTEGER
        )');
    }

    public function isEmpty()
    {
        $query = 'SELECT COUNT("id") FROM "commands" WHERE status = 0';

        return $this->db->querySingle($query) === 0;
    }

    public function add(Command $command)
    {
        $query = 'INSERT INTO commands (command, status) VALUES (:command, :status)';
        $statement = $this->db->prepare($query);
        $statement->bindValue(':command', base64_encode(serialize($command)));
        $statement->bindValue(':status', $command->getStatus());
        $statement->execute();
    }

    public function getCommand(): Command
    {
        $query = 'SELECT * FROM "commands" WHERE "status" = 0 LIMIT 1';
        $record = $this->db->querySingle($query, true);
        $command = unserialize(base64_decode($record["command"]));
        $command->id = $record['id'];

        return $command;
    }

    public function completeCommand(Command $command)
    {
        $query = 'UPDATE commands SET status = :status WHERE id = :id';
        $statement = $this->db->prepare($query);
        $statement->bindValue(':status', $command->getStatus());
        $statement->bindValue(':id', $command->getId());
        $statement->execute();
    }

    public function work()
    {
        while (! $this->isEmpty()) {
            $command = $this->getCommand();
            $command->execute();
        }
    }

    /**
     * EN:
     * For our convenience, the Queue object is a Singleton.
     *
     * RU:
     * Для удобства объектом Очереди является Одиночка.
     */
    public static function get(): Queue
    {
        static $instance;
        if (! $instance) {
            $instance = new Queue();
        }

        return $instance;
    }
}

/**
 * EN:
 * The client code.
 *
 * RU:
 * Клиентский код.
 */

$queue = Queue::get();

if ($queue->isEmpty()) {
    $queue->add(new IMDBGenresScrapingCommand());
}

$queue->work();
