set search_path="supply";

create table "part" (
  primary key ("supplier", "part_code")

  , "supplier" text
  , "part_code" text
);
