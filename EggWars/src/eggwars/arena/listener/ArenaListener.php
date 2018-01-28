<?php

/*
 *    _____                __        __
 *   | ____|  __ _    __ _ \ \      / /__ _  _ __  ___
 *   |  _|   / _` | / _` |  \ \ /\ / // _` || '__|/ __|
 *   | |___ | (_| || (_| |   \ V  V /| (_| || |   \__ \
 *   |_____| \__, | \__, |    \_/\_/  \__,_||_|   |___/
 *           |___/  |___/
 */

declare(strict_types=1);

namespace eggwars\arena\listener;

use eggwars\arena\Arena;
use eggwars\arena\shop\CustomChestInventory;
use eggwars\arena\team\Team;
use eggwars\EggWars;
use eggwars\position\EggWarsPosition;
use eggwars\utils\Color;
use pocketmine\block\Block;
use pocketmine\entity\Villager;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\tile\Chest;
use pocketmine\tile\Sign;

/**
 * Class ArenaListener
 * @package eggwars\arena\listener
 */
class ArenaListener implements Listener {

    /** @var Arena $arena */
    private $arena;

    /** @var DeathManager $deathManager */
    public $deathManager;

    /**
     * ArenaListener constructor.
     */
    public function __construct(Arena $arena) {
        $this->arena = $arena;
        $this->deathManager = new DeathManager($this);
    }

    /**
     * @param PlayerChatEvent $event
     */
    public function onChat(PlayerChatEvent $event) {
        $player = $event->getPlayer();
        if(!$this->getArena()->inGame($player)) {
            return;
        }
        $msg = $event->getMessage();
        if(!$this->getArena()->getTeamByPlayer($player) instanceof Team) {
            $this->getArena()->broadcastMessage("§8[§5Lobby§8]§7 {$player->getName()}: $msg");
            $event->setCancelled(true);
            return;
        }
        $team = $this->getArena()->getTeamByPlayer($player);
        $args = str_split($msg);
        if($args[0] == "!") {
            array_shift($args);
            $this->getArena()->broadcastMessage($team->getMinecraftColor()."[ALL] §7".$player->getName().": ".implode("", $args));
            $event->setCancelled(true);
            return;
        }
        $team->broadcastMessage($team->getMinecraftColor()."[Team] §7".$player->getName().": ".$msg);
        $event->setCancelled(true);
        return;
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onInteract(PlayerInteractEvent $event) {
        $player = $event->getPlayer();
        if(!$this->getArena()->inGame($player)) {
            $signPos = EggWarsPosition::fromArray($this->getArena()->arenaData["sign"],  $this->getArena()->arenaData["sign"][3]);
            $sign = $signPos->getLevel()->getTile($signPos->asVector3());
            if($sign instanceof Sign && $this->getArena()->getPhase() == 0) {
                if($event->getBlock()->asVector3()->equals($signPos->asVector3())) {
                    $this->getArena()->joinPlayer($event->getPlayer());
                }
            }
            return;
        }
        if($this->getArena()->getPhase() == 0) {
            if($event->getAction() != $event::RIGHT_CLICK_AIR) {
                return;
            }
            $item = $player->getInventory()->getItemInHand();
            if($item->getId() == 0) {
                return;
            }
            if(!is_string($mc = Color::getMCFromId("{$item->getId()}:{$item->getDamage()}"))) {
                return;
            }
            $team = $this->getArena()->getTeamByMinecraftColor($mc);
            $this->getArena()->addPlayerToTeam($player, $team->getTeamName());
            return;
        }
        if($event->getBlock()->getId() == Item::DRAGON_EGG) {
            $event->setCancelled($bool = $this->getArena()->teamManager->onEggBreak($player, $event->getBlock()->asVector3()));
            if(!$bool) {
                $event->getBlock()->getLevel()->setBlock($event->getBlock()->asVector3(), Block::get(0));
            }
        }
    }

    /**
     * @param EntityDamageEvent $event
     */
    public function onDamage(EntityDamageEvent $event) {
        $entity = $event->getEntity();
        if($entity instanceof Villager) {
            $lastDmg = $entity->getLastDamageCause();
            if($lastDmg instanceof EntityDamageByEntityEvent) {
                /** @var Player $damager */
                $damager = $lastDmg->getDamager();
                $this->getArena()->shopManager->openShop($damager, $this->getArena()->getTeamByPlayer($damager));
                $event->setCancelled(true);
            }
            return;
        }

        if(!$entity instanceof Player) {
            return;
        }
        if(!$this->getArena()->inGame($entity)) {
            return;
        }
        if(!($entity->getHealth()-$event->getDamage() <= 0)) {
            return;
        }
        $event->setCancelled(true);
        if((!$event instanceof EntityDamageByEntityEvent) && $event->getCause() == EntityDamageByEntityEvent::CAUSE_VOID) {
            $this->deathManager->onVoidDeath($entity);
            return;
        }
        if($event->getCause() == EntityDamageByEntityEvent::CAUSE_FIRE || $event->getCause() == EntityDamageByEntityEvent::CAUSE_FIRE_TICK) {
            $this->deathManager->onBurnDeath($entity);
            return;
        }
        if($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if(!$damager instanceof Player) {
                $this->deathManager->onBasicDeath($entity);
                return;
            }
            $this->deathManager->onDeath($entity, $damager);
            return;
        }
        $this->deathManager->onBasicDeath($entity);
    }

    /**
     * @param InventoryTransactionEvent $event
     */
    public function onTransaction(InventoryTransactionEvent $event) {

        $transaction = $event->getTransaction();

        /** @var CustomChestInventory $chestInventory */
        $chestInventory = null;

        foreach($transaction->getInventories() as $inventory) {
            if($inventory instanceof CustomChestInventory) {
                $chestInventory = $inventory;
            }
        }

        if($chestInventory === null) {
            return;
        }

        /** @var Player $player */
        $player = null;

        foreach ($inventory->getViewers() as $viewer) {
            if($viewer instanceof Player) {
                $player = $viewer;
            }
        }

        /** @var Item $targetItem */
        $targetItem = null;

        foreach ($transaction->getActions() as $inventoryAction) {
            $targetItem = $inventoryAction->getTargetItem();
        }

        if($targetItem === null || $targetItem->getId() == 0) {
            $event->setCancelled(true);
            return;
        }

        $team = $this->getArena()->getTeamByPlayer($player);

        $slot = 0;
        foreach ($chestInventory->getContents() as $chestSlot => $chestItem) {
            if($chestItem->equals($targetItem, false, false)) {
                $slot = $chestSlot;
            }
        }


        // BROWSING
        if($slot <= 8) {
            $this->getArena()->shopManager->onBrowseTransaction($player, $chestInventory, $slot);
        }

        // BUYING
        else {
            $this->getArena()->shopManager->onBuyTransaction($player, $targetItem, $slot);
        }

        $event->setCancelled(true);
    }



    /**
     * @param PlayerExhaustEvent $event
     */
    public function onExhaust(PlayerExhaustEvent $event) {
        $player = $event->getPlayer();
        if(!$player instanceof Player) {
            return;
        }
        if(($this->getArena()->getPhase() == 0) && $this->getArena()->inGame($player)) {
            $event->setCancelled();
        }
    }

    public function onLevelChange(EntityLevelChangeEvent $event) {
        $entity = $event->getEntity();
        if(!$entity instanceof Player) {
            return;
        }
        if($this->getArena()->inGame($entity)) {
            if($event->getTarget()->getName() != $this->getArena()->getLevel()->getName()) {
                $this->getArena()->disconnectPlayer($entity);
            }
        }
    }

    /**
     * @param BlockBreakEvent $event
     */
    public function onEggBreak(BlockBreakEvent $event) {
        $player = $event->getPlayer();
        if($this->getArena()->inGame($player) && $event->getBlock()->getId() == Item::DRAGON_EGG) {
            $bool = $this->getArena()->teamManager->onEggBreak($player, $event->getBlock()->asVector3());
            if(!$bool) {
                $event->getBlock()->getLevel()->setBlock($event->getBlock()->asVector3(), Block::get(0));
            }
            $event->setCancelled($bool);
        }
    }

    /**
     * @return EggWars $eggWars
     */
    public function getPlugin(): EggWars {
        return EggWars::getInstance();
    }

    /**
     * @return Arena $arena
     */
    public function getArena(): Arena {
        return $this->arena;
    }
}