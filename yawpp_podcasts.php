<?php
class YAWPP_Podcasts
{

    public static function findLatest($limit = 10, $type = '')
    {
        global $wpdb;
        
        $sql = "
            SELECT wposts.* , wpostmeta.meta_value AS yawpp_type
            FROM $wpdb->posts wposts, $wpdb->postmeta wpostmeta
            WHERE wposts.ID = wpostmeta.post_id 
            AND wpostmeta.meta_key = 'yawpp_type' 
            AND wposts.post_status = 'publish' 
            AND wposts.post_type = 'post' 
            AND wposts.post_date < NOW()";
        
        if(empty($type)) {
            $sql .= "
            AND wpostmeta.meta_value IN ('video', 'audio')";
            
            $params = array($limit);
        } else {
            $sql .= "
            AND wpostmeta.meta_value = %s";
            
            $params = array($type, $limit);
        }
        
        $sql .= "
            ORDER BY wposts.post_date DESC LIMIT %d
         ";
         
         $query = $wpdb->prepare($sql, $params);
         
         return $wpdb->get_results($query, OBJECT);
     }
}
         
         
