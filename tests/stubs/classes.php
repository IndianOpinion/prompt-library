<?php

// Minimal WordPress classes for the stub layer. Global namespace on purpose.

declare(strict_types=1);

use IndianOpinion\PromptLibrary\Tests\Stubs\State;

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @param mixed $data */
        public function __construct(public string $code = '', public string $message = '', public $data = null)
        {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /** @return mixed */
        public function get_error_data()
        {
            return $this->data;
        }
    }

    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_title = '';
        public string $post_excerpt = '';
        public string $post_content = '';
        public string $post_name = '';
        public int $post_author = 0;
        public int $post_parent = 0;

        /** @param array<string, mixed> $fields */
        public function __construct(array $fields = [])
        {
            foreach ($fields as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->{$key} = $value;
                }
            }
        }
    }

    class WP_User
    {
        public int $ID;
        public string $user_login;
        public string $user_email;
        public string $display_name;

        public function __construct(int $id)
        {
            $data = State::$users[$id];
            $this->ID = $id;
            $this->user_login = $data['login'];
            $this->user_email = $data['email'];
            $this->display_name = ucfirst($data['login']);
        }
    }

    class WP_Role
    {
        public function __construct(public string $name)
        {
        }

        public function add_cap(string $cap): void
        {
            State::$roles[$this->name]['capabilities'][$cap] = true;
        }

        public function remove_cap(string $cap): void
        {
            unset(State::$roles[$this->name]['capabilities'][$cap]);
        }
    }

    class WP_Roles
    {
        /** @var array<string, array{name: string, capabilities: array<string, bool>}> */
        public array $roles;

        public function __construct()
        {
            $this->roles = State::$roles;
        }
    }

    class WP_Site
    {
        public function __construct(public int $blog_id = 1)
        {
        }
    }

    /** @implements ArrayAccess<string, mixed> */
    class WP_REST_Request implements ArrayAccess
    {
        /** @var array<string, mixed> */
        private array $params = [];

        public function __construct(private string $method = 'GET', private string $route = '')
        {
        }

        /** @param mixed $value */
        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }

        /** @return mixed */
        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->params[$offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->params[$offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->params[(string) $offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->params[$offset]);
        }
    }

    class WP_REST_Response
    {
        /** @param mixed $data */
        public function __construct(public $data = null, public int $status = 200)
        {
        }

        /** @return mixed */
        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}
