<?php
/**
 * Aurora - A HTTP Application Server of PHP Script
 *
 * @author  panlatent@gmail.com
 * @link    https://github.com/panlatent/aurora
 * @license https://opensource.org/licenses/MIT
 */

namespace Aurora\Http\Pipeline;

use Aurora\Event\EventAcceptor;
use Aurora\Http\Request;
use Aurora\Http\Request\FirstLine;
use Aurora\Http\Request\Header as HttpHeader;
use Aurora\Http\Response;
use Aurora\Http\Server\Exception\InternalServerErrorException;
use Aurora\Http\ServerTimestampType;

class Events extends EventAcceptor
{
    /**
     * @var \Aurora\Http\Pipeline
     */
    protected $bind;

    protected $firstLine;

    protected $header;

    public function register()
    {
        $this->event->bind('pipeline:append', $this);
        $this->event->bind('http.pipeline:send', $this);
    }

    public function onAppend()
    {
        $this->bind->getData('client')->getTimestamp()->update(ServerTimestampType::RequestLast);
        $buffer = $this->bind->getBuffer();
        if ( ! $this->firstLine) {
            if (false !== ($pos = $buffer->find("\r\n"))) {
                $rawFirstLine = $buffer->pop($pos, 2);
                $this->firstLine = FirstLine::factory($rawFirstLine);
            }
        }

        if ($this->firstLine && ! $this->header) {
            if (false !== ($pos = $buffer->find("\r\n\r\n"))) {
                $rawHeader = $buffer->pop($pos, 4);
                $this->header = HttpHeader::factory($rawHeader);
            }
        }

        if ($this->header) {
            $httpContentLength =  $this->header['HTTP_CONTENT_LENGTH'];
            if (($isEmptyBody = $this->firstLine->isEmptyBody()) || $buffer->size() >= $httpContentLength) {
                $rawBody = ! $isEmptyBody ? $buffer->pop($httpContentLength) : '';
                $request = Request::factory([
                    'firstLine' => $this->firstLine,
                    'header' => $this->header,
                    'rawBody' => $rawBody
                ]);
                $this->firstLine = null;
                $this->header = null;

                $this->bind->dispatch($request);
                $this->event->fire('http.pipeline:send', [$request]);
            }
        }
    }

    public function onSend(Request $request)
    {
        if ( ! $request->isPermanenceConnection()) {
            $this->bind->getData('client')->declareClose();
        } else {
            $this->bind->getData('client')->setKeepAlive(true);
            $this->bind->getData('client')->getWriteBuffer()->flush();
        }

        $end = $this->bind->getEnd();
        if ( ! $end || ! is_object($end) || ! $end instanceof Response) {
            throw new InternalServerErrorException("pipeline end data is not a Aurora\\Http\\Response class object");
        }
    }

}