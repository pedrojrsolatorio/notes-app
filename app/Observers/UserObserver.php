<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        $folders = [
            'Work',
            'School',
            'Personal',
            'Projects',
        ];

        foreach ($folders as $name) {
            $user->folders()->create([
                'name' => $name,
            ]);
        }
    }
}
