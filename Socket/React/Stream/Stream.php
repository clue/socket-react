<?php

namespace Socket\React\Stream;

use Socket\React\SelectPoller;
use React\Stream\WritableStreamInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use Socket\Raw\Socket as RawSocket;

class Stream extends EventEmitter implements ReadableStreamInterface, WritableStreamInterface
{
    /**
     *
     * @var RawSocket
     */
    protected $socket;
    private $poller;

    private $bufferSize = 65536;

    protected $readable = true;
    protected $writable = true;
    protected $closing = false;

    public function __construct(RawSocket $socket, SelectPoller $poller)
    {
        $this->socket = $socket;
        $this->poller = $poller;

        $this->buffer = new StreamBuffer($socket, $poller);

        $that = $this;

        $this->buffer->on('error', function ($error) use ($that) {
            $that->emit('error', array($error, $that));
            $that->close();
        });

        $this->buffer->on('drain', function () use ($that) {
            $that->emit('drain');
        });

        $this->resume();
    }

    public function resume()
    {
        $this->poller->addReadSocket($this->socket->getResource(), array($this, 'handleData'));
    }

    public function pause()
    {
        $this->poller->removeReadSocket($this->socket->getResource());
    }

    public function isReadable()
    {
        return true;
    }

    public function isWritable()
    {
        return true;
    }

    public function write($data)
    {
        if (!$this->writable) {
            return;
        }

        return $this->buffer->write($data);
    }

    public function close()
    {
        if (!$this->writable && !$this->closing) {
            return;
        }

        $this->closing = false;

        $this->readable = false;
        $this->writable = false;

        $this->emit('end', array($this));
        $this->emit('close', array($this));

        $this->pause();
        $this->buffer->close();
        $this->socket->close();

        $this->removeAllListeners();
    }

    public function end($data = null)
    {
        if (!$this->writable) {
            return;
        }

        $that = $this;
        $this->buffer->on('close', function() use ($that) {
            $that->close();
        });
        $this->buffer->end($data);
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    public function handleData()
    {
        $data = $this->socket->read($this->bufferSize);

        if ($data === '') {
            $this->end();
        } else {
            $this->emit('data', array($data, $this));
        }
    }
}
